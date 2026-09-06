<?php

declare(strict_types=1);

namespace App\Services\Close;

use App\Data\Close\CloseEvaluation;
use App\Enums\CostComponent;
use App\Enums\CostCoverageStatus;
use App\Enums\CustomerPaymentEventType;
use App\Enums\FxDirection;
use App\Enums\FxSubjectType;
use App\Enums\ReconciliationSource;
use App\Models\CostAdjustment;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Models\UsageEvent;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Reconciliation\ReconciledCostQuery;
use App\Support\Billing\DecimalMath;
use App\Support\Fx\FxMath;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Preflight for closing one calendar month UTC in the reporting currency
 * (Phase E4) — reads only, writes nothing:
 *
 *  Cash inputs: succeeded payments by received_at, refunds by refunded_at,
 *  each NATIVE / CONVERTED (current frozen reporting conversion, exact
 *  fx_rate_id) / NOT CONVERTED ⇒ FX_INCOMPLETE (cash). Gateway fees use the
 *  payment's own conversion (same rate snapshot and direction); a NULL fee is
 *  FEES_INCOMPLETE (hard blocker — never zero). A payment currently
 *  `disputed` is UNRESOLVED_DISPUTES.
 *  Cost inputs: the current reconciliation of every scope in the month
 *  (+ its adjustments): provider completeness is derived from the ledger's
 *  distinct provider keys with activity in the month; communication and
 *  external need an explicit current reconciliation or CONFIRMED ZERO —
 *  NO PRODUCER is not completeness. LEDGER MOVED and EVIDENCE VOIDED /
 *  SUPERSEDED block; CONFIRMED ZERO and partial calculated coverage are
 *  informational.
 *  Figures: Gross Cash Collected, Refunds, Net Cash, Gateway Fees, Net Cash
 *  After Gateway Fees, Reconciled Service Cost, Reconciled Cash Contribution
 *  = Net Cash After Gateway Fees − Reconciled Service Cost; every figure is
 *  NULL (NOT AVAILABLE) unless all its inputs are complete.
 *  The canonical snapshot lists every input with its ids, amounts, states and
 *  FX facts in a deterministic order; input_hash = sha256 of that JSON only.
 */
final class ClosePreflight
{
    public const SNAPSHOT_VERSION = 1;

    public function __construct(private readonly ReportingCurrencyService $reporting, private readonly ReconciledCostQuery $costs) {}

    public function evaluate(string $month, ?string $reportingCurrency = null): CloseEvaluation
    {
        [$start, $end] = ReconciliationRules::month($month);
        $target = $reportingCurrency === null ? $this->reporting->current() : strtoupper($reportingCurrency);
        $conditions = [];
        $now = CarbonImmutable::now('UTC');

        if ($end->greaterThan($now)) {
            $conditions[] = ['code' => 'PERIOD_NOT_ENDED', 'blocking' => true, 'detail' => $end->format('Y-m-d')];
        }

        // ---- Cash: payments, fees, disputes ---------------------------------
        $succeeded = CustomerPaymentEventType::Succeeded->value;
        $payments = CustomerPayment::query()
            ->whereExists(static function ($q) use ($succeeded): void {
                $q->selectRaw('1')->from('customer_payment_events')->whereColumn('customer_payment_events.customer_payment_id', 'customer_payments.id')->where('customer_payment_events.event_type', $succeeded);
            })
            ->where('received_at', '>=', $start->format(CustomerPayment::TIMESTAMP_FORMAT))->where('received_at', '<', $end->format(CustomerPayment::TIMESTAMP_FORMAT))
            ->orderBy('id')->get();
        $refunds = CustomerRefund::query()->where('refunded_at', '>=', $start->format(CustomerPayment::TIMESTAMP_FORMAT))->where('refunded_at', '<', $end->format(CustomerPayment::TIMESTAMP_FORMAT))->orderBy('id')->get();

        $paymentConversions = $this->currentConversions(FxSubjectType::CustomerPayment, $payments->pluck('id')->all(), $target);
        $refundConversions = $this->currentConversions(FxSubjectType::CustomerRefund, $refunds->pluck('id')->all(), $target);

        $paymentInputs = [];
        $feeInputs = [];
        $cashNotConverted = [];
        $feesUnknown = [];
        $disputed = [];

        foreach ($payments as $payment) {
            $line = $this->cashLine('payment', $payment->id, (string) $payment->amount, 2, $payment->currency, $target, $paymentConversions->get($payment->id), $payment->received_at->toImmutable()->utc()->format('Y-m-d'));
            $paymentInputs[] = $line;

            if ($line['status'] === 'NOT CONVERTED') {
                $cashNotConverted[] = 'payment:'.$payment->id;
            }

            if ($payment->current_status === CustomerPaymentEventType::Disputed) {
                $disputed[] = (string) $payment->id;
            }

            if ($payment->gateway_fee_amount === null) {
                $feesUnknown[] = (string) $payment->id;
                $feeInputs[] = ['type' => 'gateway_fee', 'id' => $payment->id, 'payment_id' => $payment->id, 'amount' => null, 'currency' => $payment->currency, 'scale' => 2, 'status' => 'FEES UNKNOWN', 'reporting_amount' => null, 'fx_conversion_id' => null, 'fx_rate_id' => null, 'fx_rate_snapshot' => null, 'fx_direction' => null];

                continue;
            }

            // The fee follows the payment's exact conversion (fee_currency = payment currency by constraint).
            $fee = FxMath::formatAtScale((string) $payment->gateway_fee_amount, 2);
            $feeLine = ['type' => 'gateway_fee', 'id' => $payment->id, 'payment_id' => $payment->id, 'amount' => $fee, 'currency' => $payment->currency, 'scale' => 2, 'status' => $line['status'], 'reporting_amount' => null, 'fx_conversion_id' => $line['fx_conversion_id'], 'fx_rate_id' => $line['fx_rate_id'], 'fx_rate_snapshot' => $line['fx_rate_snapshot'], 'fx_direction' => $line['fx_direction']];

            if ($line['status'] === 'NATIVE') {
                $feeLine['reporting_amount'] = $fee;
            } elseif ($line['status'] === 'CONVERTED') {
                $feeLine['reporting_amount'] = FxMath::convert($fee, 2, (string) $line['fx_rate_snapshot'], FxDirection::from((string) $line['fx_direction']), 2);
            }

            $feeInputs[] = $feeLine;
        }

        $refundInputs = [];
        foreach ($refunds as $refund) {
            $line = $this->cashLine('refund', $refund->id, (string) $refund->amount, 2, $refund->currency, $target, $refundConversions->get($refund->id), $refund->refunded_at->toImmutable()->utc()->format('Y-m-d'));
            $refundInputs[] = $line;

            if ($line['status'] === 'NOT CONVERTED') {
                $cashNotConverted[] = 'refund:'.$refund->id;
            }
        }

        if ($cashNotConverted !== []) {
            $conditions[] = ['code' => 'FX_INCOMPLETE_CASH', 'blocking' => true, 'detail' => implode(',', $cashNotConverted)];
        }

        if ($feesUnknown !== []) {
            $conditions[] = ['code' => 'FEES_INCOMPLETE', 'blocking' => true, 'detail' => 'payments:'.implode(',', $feesUnknown)];
        }

        if ($disputed !== []) {
            $conditions[] = ['code' => 'UNRESOLVED_DISPUTES', 'blocking' => true, 'detail' => 'payments:'.implode(',', $disputed)];
        }

        // ---- Cost: reconciliation completeness per component ---------------
        $scopes = CostReconciliationScope::query()->where('period_start', $start->format('Y-m-d H:i:s'))->orderBy('id')->get();
        $currentIds = $scopes->pluck('current_reconciliation_id')->filter()->values();
        $reconciliations = CostReconciliation::query()->whereIn('id', $currentIds)->orderBy('id')->get()->keyBy('id');

        $expectedProviders = UsageEvent::query()->toBase()
            ->where('occurred_at', '>=', $start->format('Y-m-d H:i:s'))->where('occurred_at', '<', $end->format('Y-m-d H:i:s'))
            ->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider')->map(fn ($p) => (string) $p)->all();

        $reconciledProviders = $reconciliations->filter(fn (CostReconciliation $r) => $r->component === CostComponent::Provider)->pluck('counterparty_key')->unique()->all();

        foreach ($expectedProviders as $key) {
            if (! in_array($key, $reconciledProviders, true)) {
                $conditions[] = ['code' => 'RECONCILIATION_MISSING', 'blocking' => true, 'detail' => 'provider:'.$key];
            }
        }

        foreach ([CostComponent::Communication, CostComponent::External] as $component) {
            if ($reconciliations->filter(fn (CostReconciliation $r) => $r->component === $component)->isEmpty()) {
                $conditions[] = ['code' => 'RECONCILIATION_MISSING', 'blocking' => true, 'detail' => $component->value.' (NO PRODUCER is not completeness — record a reconciliation or CONFIRMED ZERO)'];
            }
        }

        $recConversions = $this->currentConversions(FxSubjectType::CostReconciliation, $reconciliations->keys()->all(), $target);
        $adjustments = CostAdjustment::query()->whereIn('cost_reconciliation_id', $reconciliations->keys()->all())->orderBy('id')->get();
        $adjConversions = $this->currentConversions(FxSubjectType::CostAdjustment, $adjustments->pluck('id')->all(), $target);

        $reconciliationInputs = [];
        $adjustmentInputs = [];
        $costNotConverted = [];

        foreach ($scopes as $scope) {
            if ($scope->current_reconciliation_id === null) {
                continue;
            }
            /** @var CostReconciliation $rec */
            $rec = $reconciliations->get($scope->current_reconciliation_id);
            $summary = $this->costs->describe($scope);
            $flags = $summary->flags;

            if ($summary->ledgerMoved) {
                $conditions[] = ['code' => 'LEDGER_MOVED', 'blocking' => true, 'detail' => 'reconciliation:'.$rec->id];
            }

            foreach ($flags as $flag) {
                if (str_starts_with($flag, 'EVIDENCE')) {
                    $conditions[] = ['code' => 'EVIDENCE_STALE', 'blocking' => true, 'detail' => 'reconciliation:'.$rec->id.' '.$flag];
                }
            }

            if ($rec->source === ReconciliationSource::ConfirmedZero) {
                $conditions[] = ['code' => 'CONFIRMED_ZERO', 'blocking' => false, 'detail' => $rec->component->value.':'.$rec->counterparty_key];
            }

            if ($rec->cost_coverage_status !== CostCoverageStatus::Complete) {
                $conditions[] = ['code' => 'CALCULATED_COVERAGE_PARTIAL', 'blocking' => false, 'detail' => $rec->component->value.':'.$rec->counterparty_key.' '.$rec->cost_coverage_status->value];
            }

            $line = $this->cashLine('reconciliation', $rec->id, (string) $rec->reconciled_amount, 6, $rec->currency, $target, $recConversions->get($rec->id), $rec->period_end->toImmutable()->utc()->format('Y-m-d'));
            $line += ['component' => $rec->component->value, 'counterparty_key' => $rec->counterparty_key, 'source' => $rec->source->value, 'snapshot_hash' => $rec->snapshot_hash, 'flags' => $flags];
            $reconciliationInputs[] = $line;

            if ($line['status'] === 'NOT CONVERTED') {
                $costNotConverted[] = 'reconciliation:'.$rec->id;
            }

            foreach ($adjustments->where('cost_reconciliation_id', $rec->id) as $adjustment) {
                $adj = $this->cashLine('adjustment', $adjustment->id, (string) $adjustment->amount, 6, $adjustment->currency, $target, $adjConversions->get($adjustment->id), $rec->period_end->toImmutable()->utc()->format('Y-m-d'));
                $adj += ['reconciliation_id' => $rec->id];
                $adjustmentInputs[] = $adj;

                if ($adj['status'] === 'NOT CONVERTED') {
                    $costNotConverted[] = 'adjustment:'.$adjustment->id;
                }
            }
        }

        if ($costNotConverted !== []) {
            $conditions[] = ['code' => 'FX_INCOMPLETE_COST', 'blocking' => true, 'detail' => implode(',', $costNotConverted)];
        }

        // ---- Figures (NULL = NOT AVAILABLE) --------------------------------
        $gross = self::sum($paymentInputs, 2);
        $refundTotal = self::sum($refundInputs, 2);
        $fees = $feesUnknown === [] ? self::sum($feeInputs, 2) : null;
        $net = $gross === null || $refundTotal === null ? null : self::sub($gross, $refundTotal, 2);
        $netAfterFees = $net === null || $fees === null ? null : self::sub($net, $fees, 2);
        $costBase = self::sum($reconciliationInputs, 6);
        $costAdj = self::sum($adjustmentInputs, 6);
        $reconciliationsComplete = array_filter(array_map(static fn (array $c): bool => $c['blocking'] && in_array($c['code'], ['RECONCILIATION_MISSING', 'LEDGER_MOVED', 'EVIDENCE_STALE'], true), $conditions)) === [];
        $cost = $costBase === null || $costAdj === null || ! $reconciliationsComplete ? null : self::add($costBase, $costAdj, 6);
        $blocking = array_filter($conditions, static fn (array $c): bool => $c['blocking']) !== [];
        $contribution = $netAfterFees === null || $cost === null || $blocking ? null : self::sub(self::rescale($netAfterFees, 2, 6), $cost, 6);

        $metrics = [
            'gross_cash_collected' => $gross,
            'refunds' => $refundTotal,
            'net_cash' => $net,
            'gateway_fees' => $fees,
            'net_cash_after_gateway_fees' => $netAfterFees,
            'reconciled_service_cost' => $cost,
            'reconciled_cash_contribution' => $contribution,
        ];

        $snapshot = [
            'version' => self::SNAPSHOT_VERSION,
            'month' => $start->format('Y-m'),
            'period_start' => $start->format('Y-m-d H:i:s'),
            'period_end' => $end->format('Y-m-d H:i:s'),
            'reporting_currency' => $target,
            'expected_providers' => $expectedProviders,
            'payments' => $paymentInputs,
            'gateway_fees' => $feeInputs,
            'refunds' => $refundInputs,
            'reconciliations' => $reconciliationInputs,
            'adjustments' => $adjustmentInputs,
            'metrics' => $metrics,
            'conditions' => $conditions,
        ];

        return new CloseEvaluation($start->format('Y-m'), $target, $metrics, $conditions, $snapshot, self::hash($snapshot));
    }

    /** sha256 of the canonical JSON (deterministic key order, no whitespace, ASCII-safe). */
    public static function hash(array $snapshot): string
    {
        return hash('sha256', self::canonicalJson($snapshot));
    }

    public static function canonicalJson(array $snapshot): string
    {
        return (string) json_encode(self::sortKeys($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $k => $v) {
            $out[$k] = self::sortKeys($v);
        }

        if (! $isList) {
            ksort($out);
        }

        return $out;
    }

    /**
     * @return Collection<int, FxConversion> keyed by subject id (current revision only, from the scope projection)
     */
    private function currentConversions(FxSubjectType $type, array $subjectIds, string $target): Collection
    {
        if ($subjectIds === []) {
            return collect();
        }

        $pointers = FxConversionScope::query()->where('subject_type', $type->value)->whereIn('subject_id', $subjectIds)->where('purpose', 'reporting')->where('target_currency', $target)->whereNotNull('current_conversion_id')->pluck('current_conversion_id', 'subject_id');
        $rows = $pointers->isEmpty() ? collect() : FxConversion::query()->whereIn('id', $pointers->values())->get()->keyBy('id');
        $out = collect();

        foreach ($pointers as $subjectId => $conversionId) {
            if ($rows->has($conversionId)) {
                $out->put((int) $subjectId, $rows->get($conversionId));
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function cashLine(string $type, int $id, string $amount, int $scale, string $currency, string $target, ?FxConversion $conversion, string $policyDate): array
    {
        $amount = FxMath::formatAtScale($amount, $scale);
        $line = ['type' => $type, 'id' => $id, 'amount' => $amount, 'currency' => $currency, 'scale' => $scale, 'policy_date' => $policyDate, 'status' => 'NOT CONVERTED', 'reporting_amount' => null, 'fx_conversion_id' => null, 'fx_rate_id' => null, 'fx_rate_snapshot' => null, 'fx_direction' => null];

        if ($currency === $target) {
            $line['status'] = 'NATIVE';
            $line['reporting_amount'] = $amount;
        } elseif ($conversion !== null) {
            $line['status'] = 'CONVERTED';
            $line['reporting_amount'] = $conversion->targetAmountAtScale();
            $line['fx_conversion_id'] = $conversion->id;
            $line['fx_rate_id'] = $conversion->fx_rate_id;
            $line['fx_rate_snapshot'] = (string) $conversion->rate_snapshot;
            $line['fx_direction'] = $conversion->direction->value;
        }

        return $line;
    }

    /** Σ reporting amounts, or null when any line is not reportable. */
    private static function sum(array $lines, int $scale): ?string
    {
        $total = 0;

        foreach ($lines as $line) {
            if ($line['reporting_amount'] === null) {
                return null;
            }

            $total += self::signed((string) $line['reporting_amount'], $scale);
        }

        return self::formatSigned($total, $scale);
    }

    /** Re-express a signed decimal string at a larger scale (exact). */
    private static function rescale(string $value, int $from, int $to): string
    {
        $negative = str_starts_with($value, '-');
        $scaled = DecimalMath::rescale(DecimalMath::toScaled(ltrim($value, '-'), $from), $from, $to);

        return ($negative ? '-' : '').DecimalMath::format($scaled, $to);
    }

    private static function signed(string $value, int $scale): int
    {
        return DecimalMath::toScaled(ltrim($value, '-'), $scale) * (str_starts_with($value, '-') ? -1 : 1);
    }

    private static function formatSigned(int $value, int $scale): string
    {
        return $value < 0 ? '-'.DecimalMath::format(-$value, $scale) : DecimalMath::format($value, $scale);
    }

    private static function add(string $a, string $b, int $scale): string
    {
        return self::formatSigned(self::signed($a, $scale) + self::signed($b, $scale), $scale);
    }

    private static function sub(string $a, string $b, int $scale): string
    {
        return self::formatSigned(self::signed($a, $scale) - self::signed($b, $scale), $scale);
    }
}
