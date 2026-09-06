<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Reconciliation\EvidenceAllocation;
use App\Data\Reconciliation\ReconciliationInput;
use App\Enums\CostCoverageStatus;
use App\Enums\ReconciliationSource;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Livewire\Dashboard\Finance\Concerns\HandlesReconciliationActions;
use App\Models\CostAdjustment;
use App\Models\CostInvoice;
use App\Models\CostInvoiceAllocation;
use App\Models\CostInvoiceLine;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Services\Reconciliation\CostReconciliationService;
use App\Services\Reconciliation\ReconciledCostQuery;
use App\Services\Reconciliation\ReconciliationLedgerView;
use App\Support\Rbac\Permission;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reconciliation scope detail (Phase E5.2b). One scope = (component,
 * counterparty key, calendar month UTC, currency). Shows the identity, the
 * current pointer and the token this page rendered with, the current
 * reconciliation, the full revision history (append-only rows with
 * supersedes_id, source, base, adjustments, adjusted, the FROZEN ledger
 * snapshot, evidence allocations with their frozen FX facts, reason /
 * evidence / actor / time — never recomputed) and, separately, the LIVE
 * flags of this one scope (LEDGER MOVED, EVIDENCE VOIDED / SUPERSEDED) from
 * the same describe() the close preflight uses, shown with the shared
 * banners under the preflight's own condition codes.
 *
 * Writes — Reconcile from evidence / manual evidenced / CONFIRMED ZERO and
 * Adjust — go through CostReconciliationService only. The concurrency contract
 * of reconcile is the expected current pointer rendered with the page (hidden
 * field): a changed pointer ⇒ STATE CHANGED, the pointer is refreshed, the
 * user reviews the new revision and decides again — never an automatic
 * retry. Adjust carries the durable service idempotency key (E5.2b).
 *
 * A scope with no row yet (route `reconciliation.new` with the identity in
 * the URL) renders the same page in the NOT RECONCILED state with expected
 * = none; the service creates the row on the first reconciliation.
 */
#[Title('تفاصيل نطاق التسوية | سَنَد')]
#[Layout('components.layouts.dashboard')]
class ReconciliationScopeDetail extends Component
{
    use HandlesReconciliationActions;

    public ?int $scopeId = null;

    #[Url]
    public string $component = '';

    #[Url]
    public string $counterparty = '';

    #[Url]
    public string $month = '';

    #[Url]
    public string $currency = '';

    /** The pointer this page rendered with ('' = none) — sent back as a hidden field; the service's concurrency contract. */
    public string $expectedId = '';

    public string $scopeToken = 'r:0';

    public ?string $confirming = null; // evidence | manual | zero | adjust

    // ---- reconcile from evidence ----
    public string $reconcileKey = '';

    /** @var list<array{line: string, amount: string, fx_rate_id: string}> */
    public array $evidenceRows = [['line' => '', 'amount' => '', 'fx_rate_id' => '']];

    public string $evReason = '';

    public string $evEvidence = '';

    // ---- manual evidenced ----
    public string $manualKey = '';

    public string $manAmount = '';

    public string $manReason = '';

    public string $manEvidence = '';

    // ---- confirmed zero ----
    public string $zeroKey = '';

    public string $zeroTyped = '';

    public string $zeroReason = '';

    public string $zeroEvidence = '';

    // ---- adjustment (durable key) ----
    public string $adjustKey = '';

    public string $adjAmount = '';

    public string $adjReason = '';

    public string $adjEvidence = '';

    public ?string $notice = null;

    public function mount(?CostReconciliationScope $scope = null): void
    {
        $this->authorizeManage();

        if ($scope !== null) {
            $this->scopeId = $scope->id;
            $this->component = $scope->component->value;
            $this->counterparty = $scope->counterparty_key;
            $this->month = $scope->period_start->utc()->format('Y-m');
            $this->currency = $scope->currency;
        } else {
            try {
                $identity = $this->identity();
            } catch (ReconciliationRuleException) {
                abort(404);
            }
            $existing = $this->findScope($identity);

            if ($existing !== null) {
                $this->redirectRoute('dashboard.finance.reconciliation.show', ['scope' => $existing->id]);

                return;
            }
            [$this->component, $this->counterparty, $this->month, $this->currency] = $identity;
        }

        $this->refreshRecord();
        $this->reconcileKey = self::freshKey();
        $this->manualKey = self::freshKey();
        $this->zeroKey = self::freshKey();
        $this->adjustKey = self::freshKey();
    }

    public function openConfirm(string $action): void
    {
        $this->authorizeManage();
        $this->confirming = in_array($action, ['evidence', 'manual', 'zero', 'adjust'], true) ? $action : null;
    }

    public function closeConfirm(): void
    {
        $this->confirming = null;
    }

    public function addEvidenceRow(): void
    {
        if (count($this->evidenceRows) < 20) {
            $this->evidenceRows[] = ['line' => '', 'amount' => '', 'fx_rate_id' => ''];
        }
    }

    public function removeEvidenceRow(int $index): void
    {
        unset($this->evidenceRows[$index]);
        $this->evidenceRows = array_values($this->evidenceRows) ?: [['line' => '', 'amount' => '', 'fx_rate_id' => '']];
    }

    public function reconcileFromEvidence(CostReconciliationService $service): void
    {
        $this->reconcile('reconcile', $this->reconcileKey, $service, function (): array {
            $allocations = [];
            foreach ($this->evidenceRows as $row) {
                if (trim((string) ($row['line'] ?? '')) === '' && trim((string) ($row['amount'] ?? '')) === '') {
                    continue;
                }
                $fxRateId = trim((string) ($row['fx_rate_id'] ?? ''));
                // The user picks the amount and, across currencies, the exact quote — nothing is proportioned, totalled or converted implicitly.
                $allocations[] = new EvidenceAllocation($this->positiveInt((string) ($row['line'] ?? ''), 'معرّف السطر'), (string) ($row['amount'] ?? ''), $fxRateId === '' ? null : $this->positiveInt($fxRateId, 'fx_rate_id'));
            }

            return ['source' => 'invoice', 'allocations' => $allocations, 'reasonCode' => self::optional($this->evReason), 'evidenceRef' => self::optional($this->evEvidence)];
        });
    }

    public function reconcileManual(CostReconciliationService $service): void
    {
        $this->reconcile('manual', $this->manualKey, $service, fn (): array => ['source' => 'manual_evidenced', 'reconciledAmount' => self::optional($this->manAmount), 'reasonCode' => self::optional($this->manReason), 'evidenceRef' => self::optional($this->manEvidence)]);
    }

    public function confirmZero(CostReconciliationService $service): void
    {
        $this->reconcile('zero', $this->zeroKey, $service, fn (): array => ['source' => 'confirmed_zero', 'reasonCode' => self::optional($this->zeroReason), 'evidenceRef' => self::optional($this->zeroEvidence), 'typedConfirmation' => $this->zeroTyped]);
    }

    public function adjust(CostReconciliationService $service): void
    {
        $ok = $this->attempt('adjustment', $this->adjustKey, function () use ($service): void {
            $scope = $this->scope() ?? throw new InvalidArgumentException('لا تسوية حالية لهذا النطاق؛ التعديل يُضاف إلى التسوية الحالية فقط.');
            $this->assertRenderedToken($this->scopeToken, $scope->stateToken());
            $current = $scope->current_reconciliation_id ?? throw new InvalidArgumentException('لا تسوية حالية لهذا النطاق؛ التعديل يُضاف إلى التسوية الحالية فقط.');

            // The attempt key IS the service idempotency key: a replay returns the same adjustment, a different payload conflicts.
            $adjustment = $service->adjust($current, $this->adjAmount, $this->adjReason, $this->adjEvidence, $this->adjustKey);
            $this->notice = $adjustment->wasRecentlyCreated
                ? "أُضيف التعديل #{$adjustment->id} ({$adjustment->amount} {$adjustment->currency}) على التسوية #{$adjustment->cost_reconciliation_id}؛ المبلغ الأساسي لم يتغيّر."
                : "التعديل #{$adjustment->id} مسجَّل مسبقًا بنفس المفتاح والحقائق؛ لم يُكتب شيء جديد.";
        });

        if ($ok) {
            $this->reset('adjAmount', 'adjReason', 'adjEvidence', 'confirming');
            $this->adjustKey = self::freshKey();
        }
    }

    public function render(ReconciliationLedgerView $ledger, ReconciledCostQuery $query)
    {
        $this->authorizeManage();
        $user = auth()->user();
        $scope = $this->scope();
        [$component, $counterparty, $month, $currency] = [$this->component, $this->counterparty, $this->month, $this->currency];

        $revisions = $scope === null ? collect() : CostReconciliation::query()->where('scope_id', $scope->id)->orderByDesc('id')->get();
        $revisionIds = $revisions->pluck('id')->all();
        $adjustmentSums = $ledger->adjustments($revisionIds);
        $adjustments = $revisionIds === [] ? collect() : CostAdjustment::query()->whereIn('cost_reconciliation_id', $revisionIds)->orderBy('id')->get()->groupBy('cost_reconciliation_id');
        $evidence = $revisionIds === [] ? collect() : CostInvoiceAllocation::query()->whereIn('cost_reconciliation_id', $revisionIds)->orderBy('id')->get();
        $evidenceLines = $evidence->isEmpty() ? collect() : CostInvoiceLine::query()->whereIn('id', $evidence->pluck('cost_invoice_line_id')->unique()->all())->get()->keyBy('id');
        $evidenceByRevision = $evidence->groupBy('cost_reconciliation_id');
        $current = $scope?->current_reconciliation_id === null ? null : $revisions->firstWhere('id', $scope->current_reconciliation_id);

        // Live status of THIS one scope — the same describe() the close preflight derives LEDGER_MOVED / EVIDENCE_STALE from.
        $blocking = [];
        $info = [];
        $live = null;
        if ($scope !== null && $current !== null) {
            $live = $query->describe($scope);
            if ($live->ledgerMoved) {
                $blocking[] = 'LEDGER_MOVED · reconciliation:'.$current->id.' — LEDGER MOVED SINCE RECONCILIATION (period close blocked until re-reconciled)';
            }
            foreach ($live->flags as $flag) {
                if (str_starts_with($flag, 'EVIDENCE')) {
                    $blocking[] = 'EVIDENCE_STALE · reconciliation:'.$current->id.' '.$flag;
                }
            }
            if ($current->source === ReconciliationSource::ConfirmedZero) {
                $info[] = 'CONFIRMED_ZERO · '.$component.':'.$counterparty.' — an attestation, not $0';
            }
            if ($current->cost_coverage_status !== CostCoverageStatus::Complete) {
                $info[] = 'CALCULATED_COVERAGE_PARTIAL · '.$component.':'.$counterparty.' '.$current->cost_coverage_status->value.' — variance UNKNOWN';
            }
        }

        $eligible = $this->confirming === 'evidence' ? $ledger->eligibleEvidence($component, $counterparty) : collect();
        $quotes = [];
        foreach ($eligible->pluck('invoice')->unique('id') as $invoice) {
            /** @var CostInvoice $invoice */
            if ($invoice->currency !== $currency) {
                $quotes[$invoice->id] = $ledger->quotesFor($invoice, $currency);
            }
        }

        return view('livewire.dashboard.finance.reconciliation-scope-detail', [
            'scope' => $scope,
            'identity' => ['component' => $component, 'counterparty' => $counterparty, 'month' => $month, 'currency' => $currency],
            'current' => $current,
            'revisions' => $revisions,
            'adjustmentSums' => $adjustmentSums,
            'adjustments' => $adjustments,
            'evidenceByRevision' => $evidenceByRevision,
            'evidenceLines' => $evidenceLines,
            'blocking' => $blocking,
            'info' => $info,
            'live' => $live,
            'eligible' => $eligible,
            'quotes' => $quotes,
            'canAdjust' => $current !== null,
            'canAudit' => $scope !== null && (bool) $user->can(Permission::AuditView->value),
            'auditUrl' => $scope === null ? null : route('dashboard.audit', ['subject_type' => 'CostReconciliationScope', 'subject_id' => $scope->id]),
            'fxUrl' => route('dashboard.finance.fx'),
        ]);
    }

    /**
     * One reconcile attempt: the rendered pointer is the contract (hidden field, never re-read before the call);
     * success on a scope that had no row redirects to its new detail page.
     *
     * @param  callable(): array<string, mixed>  $input  source-specific ReconciliationInput arguments
     */
    private function reconcile(string $form, string $attemptKey, CostReconciliationService $service, callable $input): void
    {
        $expected = $this->expectedId; // rendered pointer
        $created = null;

        $ok = $this->attempt($form, $attemptKey, function () use ($service, $input, $expected, &$created): void {
            $scope = $this->scope();
            $this->assertRenderedToken($this->scopeToken, $scope?->stateToken() ?? 'r:0');

            $reconciliation = $service->reconcile(new ReconciliationInput(...array_merge([
                'component' => $this->component, 'counterpartyKey' => $this->counterparty, 'month' => $this->month, 'currency' => $this->currency,
                'expectedCurrentReconciliationId' => $expected === '' ? null : $this->positiveInt($expected, 'التسوية الحالية المتوقعة'),
            ], $input())));
            $created = $reconciliation;

            $label = $reconciliation->source === ReconciliationSource::ConfirmedZero ? 'CONFIRMED ZERO' : $reconciliation->reconciled_amount.' '.$reconciliation->currency;
            $this->notice = "سُجِّلت التسوية #{$reconciliation->id} (المراجعة الحالية الآن) = {$label} · Calculated known {$reconciliation->calculated_known_amount} · coverage {$reconciliation->cost_coverage_status->value}".($reconciliation->supersedes_id ? " · تحلّ محل #{$reconciliation->supersedes_id}" : '').'.';
        });

        if (! $ok) {
            return;
        }

        if ($this->scopeId === null && $created !== null) {
            $this->redirectRoute('dashboard.finance.reconciliation.show', ['scope' => $created->scope_id]);

            return;
        }

        $this->reset('evidenceRows', 'evReason', 'evEvidence', 'manAmount', 'manReason', 'manEvidence', 'zeroTyped', 'zeroReason', 'zeroEvidence', 'confirming');
        $this->reconcileKey = self::freshKey();
        $this->manualKey = self::freshKey();
        $this->zeroKey = self::freshKey();
        $this->refreshRecord(); // the page now renders the NEW pointer; any further reconciliation is a fresh, explicit decision
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} validated identity [component, counterparty, month, currency] */
    private function identity(): array
    {
        $component = ReconciliationRules::component($this->component);
        $counterparty = ReconciliationRules::requiredRef($this->counterparty, 64, 'counterparty_key');
        [$start] = ReconciliationRules::month($this->month);
        $currency = ReconciliationRules::currency($this->currency, 'currency');

        return [$component->value, $counterparty, $start->format('Y-m'), $currency];
    }

    /** @param array{0: string, 1: string, 2: string, 3: string} $identity */
    private function findScope(array $identity): ?CostReconciliationScope
    {
        return CostReconciliationScope::query()->where('component', $identity[0])->where('counterparty_key', $identity[1])
            ->where('period_start', CarbonImmutable::createFromFormat('!Y-m', $identity[2], 'UTC')->format('Y-m-d H:i:s'))->where('currency', $identity[3])->first();
    }

    private function scope(): ?CostReconciliationScope
    {
        if ($this->scopeId !== null) {
            return CostReconciliationScope::query()->findOrFail($this->scopeId);
        }

        $scope = $this->findScope([$this->component, $this->counterparty, $this->month, $this->currency]);
        if ($scope !== null) {
            $this->scopeId = $scope->id; // created by the service since this page was opened
        }

        return $scope;
    }

    /** Re-read the pointer / token — never re-run an action. */
    protected function refreshRecord(): void
    {
        $scope = $this->scope();
        $this->expectedId = $scope?->current_reconciliation_id === null ? '' : (string) $scope->current_reconciliation_id;
        $this->scopeToken = $scope?->stateToken() ?? 'r:0';
    }
}
