<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\Data\Fx\ReportingLine;
use App\Data\Fx\ReportingTotal;
use App\Enums\CustomerPaymentEventType;
use App\Enums\FxSubjectType;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Support\Billing\DecimalMath;
use App\Support\Fx\FxMath;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Reporting-currency VIEW (Phase E3) — reads only, never converts:
 *  - Cash: succeeded payments by received_at and refunds by refunded_at in a
 *    UTC window, each as NATIVE / CONVERTED (frozen fx_conversions row,
 *    exact fx_rate_id) / NOT CONVERTED; totals Gross Cash Collected, Refunds
 *    and Net Cash in the reporting currency only when every line qualifies;
 *  - Cost: the current reconciliation of every scope in a month range, same
 *    rules on the Base Reconciled Amount (adjustments stay in native currency).
 * The originals are always shown; nothing here is revenue or profit.
 */
final class ReportingView
{
    public function __construct(private readonly ReportingCurrencyService $reporting) {}

    /**
     * @return array{currency: string, lines: list<ReportingLine>, totals: array<string, ReportingTotal>}
     */
    public function cash(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($to <= $from || $from->diffInDays($to) > 366) {
            throw new InvalidArgumentException('نطاق غير صالح (حتى 366 يومًا).');
        }

        $target = $this->reporting->current();
        $succeeded = CustomerPaymentEventType::Succeeded->value;
        $fromValue = $from->format(CustomerPayment::TIMESTAMP_FORMAT);
        $toValue = $to->format(CustomerPayment::TIMESTAMP_FORMAT);

        $payments = CustomerPayment::query()
            ->whereExists(static function ($q) use ($succeeded): void {
                $q->selectRaw('1')->from('customer_payment_events')->whereColumn('customer_payment_events.customer_payment_id', 'customer_payments.id')->where('customer_payment_events.event_type', $succeeded);
            })
            ->where('received_at', '>=', $fromValue)->where('received_at', '<', $toValue)->orderBy('id')->get();
        $refunds = CustomerRefund::query()->where('refunded_at', '>=', $fromValue)->where('refunded_at', '<', $toValue)->orderBy('id')->get();

        $paymentLines = $this->lines(FxSubjectType::CustomerPayment, $payments, $target);
        $refundLines = $this->lines(FxSubjectType::CustomerRefund, $refunds, $target);

        $gross = self::total('Gross Cash Collected', $target, $paymentLines, 2);
        $refunded = self::total('Refunds', $target, $refundLines, 2);
        $net = new ReportingTotal('Net Cash', $target, $gross->amount === null || $refunded->amount === null ? null : self::sub($gross->amount, $refunded->amount, 2),
            $gross->lines + $refunded->lines, $gross->native + $refunded->native, $gross->converted + $refunded->converted, $gross->notConverted + $refunded->notConverted);

        return ['currency' => $target, 'lines' => [...$paymentLines, ...$refundLines], 'totals' => ['gross' => $gross, 'refunds' => $refunded, 'net' => $net]];
    }

    /**
     * @return array{currency: string, lines: list<ReportingLine>, totals: array<string, ReportingTotal>}
     */
    public function cost(string $fromMonth, string $toMonth): array
    {
        [$from] = ReconciliationRules::month($fromMonth);
        [, $to] = ReconciliationRules::month($toMonth);

        if ($to <= $from || $from->diffInMonths($to) > 13) {
            throw new InvalidArgumentException('نطاق الأشهر غير صالح (حتى 13 شهرًا).');
        }

        $target = $this->reporting->current();
        $ids = CostReconciliationScope::query()->where('period_start', '>=', $from->format('Y-m-d H:i:s'))->where('period_start', '<', $to->format('Y-m-d H:i:s'))->whereNotNull('current_reconciliation_id')->orderBy('id')->pluck('current_reconciliation_id');
        $reconciliations = CostReconciliation::query()->whereIn('id', $ids)->orderBy('id')->get();
        $lines = $this->lines(FxSubjectType::CostReconciliation, $reconciliations, $target);

        return ['currency' => $target, 'lines' => $lines, 'totals' => ['base' => self::total('Base Reconciled Cost', $target, $lines, 6)]];
    }

    /**
     * @param  Collection<int, Model>  $subjects
     * @return list<ReportingLine>
     */
    private function lines(FxSubjectType $type, Collection $subjects, string $target): array
    {
        $ids = $subjects->pluck('id')->all();
        $current = FxConversionScope::query()->where('subject_type', $type->value)->whereIn('subject_id', $ids)->where('purpose', 'reporting')->where('target_currency', $target)->whereNotNull('current_conversion_id')->pluck('current_conversion_id', 'subject_id');
        // Only the ids the projection points at are ever requested — nothing else on fx_conversions.
        $conversions = $current->isEmpty() ? collect() : FxConversion::query()->whereIn('id', $current->values())->get()->keyBy('id');
        $out = [];

        foreach ($subjects as $subject) {
            $currency = (string) $subject->getAttribute('currency');
            $amount = FxMath::formatAtScale((string) $subject->getAttribute($type->amountField()), $type->scale());
            $date = $type->policyDate($subject)->format('Y-m-d');

            if ($currency === $target) {
                $out[] = new ReportingLine($type->value, $subject->getKey(), $date, $amount, $currency, 'NATIVE', $amount, null, null, null, null, null);

                continue;
            }

            /** @var FxConversion|null $conversion */
            $conversion = $current->has($subject->getKey()) ? $conversions->get($current->get($subject->getKey())) : null;

            if ($conversion === null) {
                $out[] = new ReportingLine($type->value, $subject->getKey(), $date, $amount, $currency, 'NOT CONVERTED', null, null, null, null, null, null);

                continue;
            }

            $out[] = new ReportingLine($type->value, $subject->getKey(), $date, $amount, $currency, 'CONVERTED', $conversion->targetAmountAtScale(), $conversion->fx_rate_id, $conversion->fx_rate_date->format('Y-m-d'), (string) $conversion->rate_snapshot, $conversion->direction->value, $conversion->id);
        }

        return $out;
    }

    /**
     * @param  list<ReportingLine>  $lines
     */
    private static function total(string $label, string $target, array $lines, int $scale): ReportingTotal
    {
        $native = $converted = $missing = 0;
        $sum = 0;

        foreach ($lines as $line) {
            match ($line->status) {
                'NATIVE' => $native++,
                'CONVERTED' => $converted++,
                default => $missing++,
            };

            if ($line->reportingAmount() !== null) {
                $sum += DecimalMath::toScaled($line->reportingAmount(), $scale);
            }
        }

        return new ReportingTotal($label, $target, $missing > 0 ? null : DecimalMath::format($sum, $scale), count($lines), $native, $converted, $missing);
    }

    private static function sub(string $a, string $b, int $scale): string
    {
        $value = DecimalMath::toScaled($a, $scale) - DecimalMath::toScaled($b, $scale);

        return $value < 0 ? '-'.DecimalMath::format(-$value, $scale) : DecimalMath::format($value, $scale);
    }
}
