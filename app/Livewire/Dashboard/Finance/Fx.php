<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Enums\FxConversionPurpose;
use App\Enums\FxSubjectType;
use App\Exceptions\Fx\FxRuleException;
use App\Livewire\Dashboard\Finance\Concerns\HandlesFxActions;
use App\Models\FxPair;
use App\Models\FxRate;
use App\Models\FxRateScope;
use App\Services\Fx\FxPairBook;
use App\Services\Fx\ReportingCurrencyService;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * FX pairs and the reporting currency (Phase E3 → E5.2c operational UI) —
 * `finance.fx.manage` on the route, the mount and every action.
 *
 * A pair is CANONICAL: one row covers two currencies in ONE official
 * orientation (1 base = rate × quote); the reverse pair is refused, and a
 * quote is never flipped in storage — `direct` / `inverse` is a display
 * derivation at conversion time only.
 *
 * Changing the reporting currency states the code the page rendered
 * (`expectedCurrentCurrency`, hidden) and types the new code verbatim; the
 * service re-reads the truth under a row lock, so a concurrent change is
 * refused as stale instead of overwriting. It NEVER recomputes a frozen
 * conversion — only NATIVE / CONVERTED / NOT CONVERTED labels change, and the
 * impact preview below says exactly how many subjects each label would cover
 * inside ONE bounded policy-date window (counts only, three aggregates per
 * subject type, nothing materialised). The preview is read-only, on demand and
 * informational: the services stay the authority.
 */
#[Title('أزواج الصرف وعملة التقرير | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Fx extends Component
{
    use HandlesFxActions;

    // ---- create pair ----
    public string $pairKey = '';

    public string $pairBase = '';

    public string $pairQuote = '';

    // ---- reporting currency ----
    public string $rcKey = '';

    public string $rcExpected = '';

    public string $rcCode = '';

    public string $rcTyped = '';

    public string $rcReason = '';

    // ---- impact preview window (UTC, bounded like every other finance window) ----
    public string $impactFrom = '';

    public string $impactTo = '';

    public ?string $confirming = null; // pair | currency

    /** On-demand impact preview of ONE candidate code: page state, never cached or stored. */
    public ?array $impact = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManage();
        $this->pairKey = self::freshKey();
        $this->rcKey = self::freshKey();
        $this->rcExpected = app(ReportingCurrencyService::class)->current();

        $now = CarbonImmutable::now('UTC');
        $this->impactTo = $now->format('Y-m-d');
        $this->impactFrom = $now->subDays(89)->format('Y-m-d');
    }

    public function openConfirm(string $action): void
    {
        $this->authorizeManage();
        $this->confirming = in_array($action, ['pair', 'currency'], true) ? $action : null;
    }

    public function closeConfirm(): void
    {
        $this->confirming = null;
        $this->impact = null;
    }

    public function createPair(FxPairBook $book): void
    {
        $ok = $this->attempt('pair', $this->pairKey, function () use ($book): void {
            $pair = $book->create($this->pairBase, $this->pairQuote);
            $this->notice = "أُنشئ الزوج {$pair->pair_key} بالاتجاه الرسمي {$pair->base_currency}/{$pair->quote_currency} (1 {$pair->base_currency} = rate × {$pair->quote_currency}).";
        });

        if ($ok) {
            $this->reset('pairBase', 'pairQuote', 'confirming');
            $this->pairKey = self::freshKey();
        }
    }

    /** Read-only, on demand: what the candidate code would label, without writing or recomputing anything. */
    public function previewImpact(ReportingCurrencyService $reporting): void
    {
        $this->authorizeManage();
        $this->resetErrorBag('currency');

        try {
            $code = FxPairBook::currency($this->rcCode, 'reporting_currency');
        } catch (FxRuleException $e) {
            $this->addError('currency.rule', $e->rule.' — '.$e->getMessage());

            return;
        }

        try {
            // The preview is bounded like every other finance window: a policy-date window of at most FxRates::MAX_DAYS.
            [$from, $to] = FxRates::window($this->impactFrom, $this->impactTo);
        } catch (InvalidArgumentException $e) {
            $this->impact = null;
            $this->addError('currency.validation', $e->getMessage());

            return;
        }

        $this->impact = [
            'code' => $code,
            'current' => $reporting->current(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'rows' => self::impactRows($code, $from, $to->addDay()),
            'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
        ];
    }

    public function setReportingCurrency(ReportingCurrencyService $service): void
    {
        $ok = $this->attempt('currency', $this->rcKey, function () use ($service): void {
            // The code this page rendered is the concurrency contract; a changed value is refused, never overwritten.
            $code = $service->change($this->rcCode, $this->rcTyped, $this->rcExpected, self::optional($this->rcReason));
            $this->notice = "عملة التقرير الآن {$code}. لم يُعَد حساب أي تحويل مجمَّد؛ تغيّرت التسميات فقط (NATIVE / CONVERTED / NOT CONVERTED).";
        });

        if ($ok) {
            $this->reset('rcCode', 'rcTyped', 'rcReason', 'confirming');
            $this->impact = null;
            $this->rcKey = self::freshKey();
            $this->refreshRecord();
        }
    }

    public function render(ReportingCurrencyService $reporting)
    {
        $this->authorizeManage();
        $user = auth()->user();
        $pairs = FxPair::query()->orderBy('pair_key')->get();

        // One grouped query per figure — never per pair.
        $scopes = FxRateScope::query()->selectRaw('fx_pair_id, COUNT(*) AS scopes, MAX(rate_date) AS latest')->groupBy('fx_pair_id')->get()->keyBy('fx_pair_id');
        $revisions = FxRate::query()->selectRaw('fx_pair_id, COUNT(*) AS revisions')->groupBy('fx_pair_id')->get()->keyBy('fx_pair_id');

        return view('livewire.dashboard.finance.fx', [
            'pairs' => $pairs,
            'scopes' => $scopes,
            'revisions' => $revisions,
            'reportingCurrency' => $reporting->current(),
            'canAudit' => (bool) $user->can(Permission::AuditView->value),
            'auditUrl' => route('dashboard.audit', ['action' => 'finance.reporting_currency_changed']),
        ]);
    }

    /**
     * Per subject type inside ONE bounded policy-date window: how many rows
     * would be NATIVE (same currency as the candidate), CONVERTED (a current
     * frozen conversion to it already exists) or NOT CONVERTED.
     *
     * Aggregates only — three COUNT queries per type, no row and no id list is
     * ever materialised, so the work is bounded by the window and never by the
     * size of the table. Reads nothing but counts: no write, no recomputation.
     *
     * @param  CarbonImmutable  $end  exclusive
     * @return list<array{type: string, native: int, converted: int, not_converted: int}>
     */
    private static function impactRows(string $code, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];

        foreach (FxSubjectType::cases() as $type) {
            $table = (new ($type->modelClass()))->getTable();
            $native = self::windowed($type, $start, $end)->where('currency', $code)->count();
            $foreign = self::windowed($type, $start, $end)->where('currency', '!=', $code)->count();
            $converted = self::windowed($type, $start, $end)->where('currency', '!=', $code)
                ->whereExists(static fn ($q) => $q->selectRaw('1')->from('fx_conversion_scopes')
                    ->whereColumn('fx_conversion_scopes.subject_id', $table.'.id')
                    ->where('fx_conversion_scopes.subject_type', $type->value)
                    ->where('fx_conversion_scopes.purpose', FxConversionPurpose::Reporting->value)
                    ->where('fx_conversion_scopes.target_currency', $code)
                    ->whereNotNull('fx_conversion_scopes.current_conversion_id'))
                ->count();

            $out[] = ['type' => $type->value, 'native' => $native, 'converted' => $converted, 'not_converted' => $foreign - $converted];
        }

        return $out;
    }

    /**
     * One subject type restricted to [start, end) on ITS OWN policy date — the
     * same date the conversion of that subject must be quoted on (payment
     * received_at · refund refunded_at · reconciliation and adjustment
     * period_end).
     *
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    private static function windowed(FxSubjectType $type, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $query = ($type->modelClass())::query();
        $from = $start->format('Y-m-d H:i:s');
        $to = $end->format('Y-m-d H:i:s');

        return match ($type) {
            FxSubjectType::CustomerPayment => $query->where('received_at', '>=', $from)->where('received_at', '<', $to),
            FxSubjectType::CustomerRefund => $query->where('refunded_at', '>=', $from)->where('refunded_at', '<', $to),
            FxSubjectType::CostReconciliation => $query->where('period_end', '>=', $from)->where('period_end', '<', $to),
            FxSubjectType::CostAdjustment => $query->whereExists(static fn ($q) => $q->selectRaw('1')->from('cost_reconciliations')
                ->whereColumn('cost_reconciliations.id', 'cost_adjustments.cost_reconciliation_id')
                ->where('cost_reconciliations.period_end', '>=', $from)->where('cost_reconciliations.period_end', '<', $to)),
        };
    }

    protected function refreshRecord(): void
    {
        $this->rcExpected = app(ReportingCurrencyService::class)->current();
    }
}
