<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Exceptions\Close\CloseRuleException;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Livewire\Dashboard\Finance\Concerns\HandlesCloseActions;
use App\Models\FinancePeriodClose;
use App\Models\FinancePeriodCloseScope;
use App\Services\Close\ClosePreflight;
use App\Services\Close\PeriodCloseService;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Reporting\FrozenCloseReader;
use App\Support\Rbac\Permission;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Period close (Phase E4 → E5.2c operational UI). `finance.view` opens the
 * page read-only; `finance.close_period` (super_admin only) is re-checked on
 * every action here AND again inside the service.
 *
 * Rendering costs nothing but the frozen rows: preflight is NEVER executed on
 * render — the user asks for it with RUN PREFLIGHT, and what comes back is an
 * informational PREVIEW with the moment it was taken. `PeriodCloseService`
 * re-evaluates the month itself inside the close transaction and is the only
 * authority: a preview that has gone stale cannot let a blocked month close,
 * and a stale "BLOCKED" preview cannot stop a month that is now clean.
 *
 * The history is read from the frozen close rows only (FrozenCloseReader);
 * DRIFT SINCE CLOSE is an explicit on-demand check per revision. Frozen
 * values never change either way. Nothing on this page is revenue, gross
 * profit or margin.
 */
#[Title('إقفال الفترة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class PeriodClose extends Component
{
    use HandlesCloseActions;

    /** Blocking preflight codes that a role can act on, with the page that resolves them. */
    private const RESOLVERS = [
        'FEES_INCOMPLETE' => ['dashboard.finance.payments', Permission::FinancePaymentsManage, 'المدفوعات'],
        'UNRESOLVED_DISPUTES' => ['dashboard.finance.payments', Permission::FinancePaymentsManage, 'المدفوعات'],
        'FX_INCOMPLETE_CASH' => ['dashboard.finance.fx.conversions', Permission::FinanceFxManage, 'تحويلات التقرير'],
        'FX_INCOMPLETE_COST' => ['dashboard.finance.fx.conversions', Permission::FinanceFxManage, 'تحويلات التقرير'],
        'RECONCILIATION_MISSING' => ['dashboard.finance.reconciliation', Permission::FinanceReconcile, 'نطاقات التسوية'],
        'LEDGER_MOVED' => ['dashboard.finance.reconciliation', Permission::FinanceReconcile, 'نطاقات التسوية'],
        'EVIDENCE_STALE' => ['dashboard.finance.reconciliation', Permission::FinanceReconcile, 'نطاقات التسوية'],
    ];

    #[Url]
    public string $month = '';

    /** The close pointer this page rendered ('' = none) — the service's concurrency contract. */
    public string $expectedCloseId = '';

    // ---- close ----
    public string $closeKey = '';

    public string $closeTyped = '';

    // ---- reopen ----
    public string $reopenKey = '';

    public string $reopenCloseId = '';

    public string $reopenTyped = '';

    public string $reopenReason = '';

    public string $reopenEvidence = '';

    public ?string $confirming = null; // close | reopen

    /** On-demand preflight PREVIEW: page state only, never authoritative, never written. */
    public ?array $preview = null;

    /** @var array<int, bool> on-demand drift answers keyed by close id (never computed on render) */
    public array $driftChecks = [];

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManage();

        if ($this->month === '') {
            $this->month = CarbonImmutable::now('UTC')->subMonth()->format('Y-m');
        }

        $this->closeKey = self::freshKey();
        $this->reopenKey = self::freshKey();
        $this->refreshRecord();
    }

    public function updatedMonth(): void
    {
        // A preview belongs to ONE month; changing the month drops it rather than showing it against the wrong period.
        $this->preview = null;
        $this->driftChecks = [];
        $this->confirming = null;
        $this->refreshRecord();
    }

    /**
     * Run preflight ON DEMAND (finance.view). The answer is a preview of this
     * moment: informational, never stored, never a precondition the service
     * trusts.
     */
    public function runPreflight(ClosePreflight $preflight): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();
        $this->notice = null;

        try {
            $evaluation = $preflight->evaluate($this->month);
        } catch (ReconciliationRuleException|CloseRuleException|InvalidArgumentException $e) {
            $this->preview = null;
            $this->addError('preflight.validation', $e->getMessage());

            return;
        }

        $this->preview = [
            'month' => $evaluation->month,
            'currency' => $evaluation->reportingCurrency,
            'hash' => $evaluation->inputHash,
            'metrics' => $evaluation->metrics,
            'conditions' => $evaluation->conditions,
            'blocking' => $evaluation->blocking(),
            'can_close' => $evaluation->canClose(),
            'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
        ];
    }

    public function clearPreview(): void
    {
        $this->preview = null;
    }

    public function openConfirm(string $action): void
    {
        $this->authorizeClose();
        $this->confirming = in_array($action, ['close', 'reopen'], true) ? $action : null;
    }

    public function closeConfirm(): void
    {
        $this->confirming = null;
    }

    public function selectReopen(int $closeId): void
    {
        $this->authorizeClose();
        $this->reopenCloseId = (string) $closeId;
        $this->confirming = 'reopen';
    }

    public function close(PeriodCloseService $service): void
    {
        $this->authorizeClose();
        $expected = $this->expectedCloseId; // the pointer this page rendered

        $ok = $this->attempt('close', $this->closeKey, function () use ($service, $expected): void {
            // The service re-evaluates the month itself: the preview above never decides this.
            $close = $service->close($this->month, $expected === '' ? null : $this->positiveInt($expected, 'الإقفال الحالي المتوقع'), $this->closeKey, trim($this->closeTyped));

            $this->notice = ($close->wasRecentlyCreated ? 'أُقفل الشهر ' : 'الشهر مُقفل مسبقًا بنفس المفتاح ونفس المدخلات؛ لم يُكتب شيء جديد — ')
                ."{$close->month()} (السجل #{$close->id}، المراجعة {$close->revision}، hash ".substr((string) $close->input_hash, 0, 12).'…). Reconciled Cash Contribution: '.($close->reconciled_cash_contribution ?? 'NOT AVAILABLE').' '.$close->reporting_currency.'.';
        });

        if ($ok) {
            $this->reset('closeTyped', 'confirming');
            $this->closeKey = self::freshKey();
            $this->preview = null; // the frozen close is the truth now; a pre-close preview would only mislead
            $this->refreshRecord();
        }
    }

    public function reopen(PeriodCloseService $service): void
    {
        $this->authorizeClose();
        $expected = $this->expectedCloseId;

        $ok = $this->attempt('reopen', $this->reopenKey, function () use ($service, $expected): void {
            // The attempt key IS the service idempotency key: the same key with the same facts returns the same reopen row.
            $record = $service->reopen(
                $this->positiveInt($this->reopenCloseId, 'معرّف الإقفال'),
                $expected === '' ? null : $this->positiveInt($expected, 'الإقفال الحالي المتوقع'),
                $this->reopenReason, $this->reopenEvidence, trim($this->reopenTyped), $this->reopenKey,
            );

            $this->notice = ($record->wasRecentlyCreated ? 'أُعيد فتح الشهر ' : 'إعادة الفتح مسجَّلة مسبقًا بنفس المفتاح ونفس الحقائق؛ لم يُكتب شيء جديد — ')
                ."{$record->month()} بالسجل #{$record->id} (الإقفال #{$record->reopened_close_id} محفوظ بلا تعديل).";
        });

        if ($ok) {
            $this->reset('reopenCloseId', 'reopenTyped', 'reopenReason', 'reopenEvidence', 'confirming');
            $this->reopenKey = self::freshKey();
            $this->refreshRecord();
        }
    }

    /** On-demand only (finance.view): compare the live hash with ONE historical revision. Frozen values never change. */
    public function checkDrift(int $closeId, PeriodCloseService $service): void
    {
        $this->authorizeManage();
        $this->driftChecks[$closeId] = $service->drift(FinancePeriodClose::query()->findOrFail($closeId));
    }

    public function render(ReportingCurrencyService $reporting, FrozenCloseReader $reader)
    {
        $this->authorizeManage();
        $user = auth()->user();
        $scope = $this->scope();

        return view('livewire.dashboard.finance.period-close', [
            'scope' => $scope,
            'history' => $scope === null ? collect() : $reader->history($scope), // frozen rows only — no preflight per row
            'drift' => $this->driftChecks,
            'blockerLinks' => $this->blockerLinks(),
            'reportingCurrency' => $reporting->current(),
            'canClose' => (bool) $user->can(Permission::FinanceClosePeriod->value),
            'canExport' => (bool) $user->can(Permission::FinanceExport->value),
            'canAudit' => (bool) $user->can(Permission::AuditView->value),
        ]);
    }

    /**
     * One deep link per blocking preview condition — only to a page THIS user
     * may open. A blocker the user cannot resolve still shows, without a link.
     *
     * @return array<string, array{href: string, label: string}> keyed by the banner text
     */
    private function blockerLinks(): array
    {
        $user = auth()->user();
        $links = [];

        foreach ($this->preview['blocking'] ?? [] as $item) {
            $code = strtok($item, ' (');
            $resolver = self::RESOLVERS[$code] ?? null;

            if ($resolver === null || ! ($user?->can($resolver[1]->value) ?? false)) {
                continue;
            }

            $links[$item] = ['href' => route($resolver[0]), 'label' => $resolver[2]];
        }

        return $links;
    }

    private function scope(): ?FinancePeriodCloseScope
    {
        try {
            [$start] = ReconciliationRules::month($this->month);
        } catch (ReconciliationRuleException) {
            return null;
        }

        return FinancePeriodCloseScope::query()->where('period_start', $start->format('Y-m-d H:i:s'))->where('reporting_currency', app(ReportingCurrencyService::class)->current())->first();
    }

    protected function refreshRecord(): void
    {
        $this->expectedCloseId = (string) ($this->scope()?->current_close_id ?? '');
    }
}
