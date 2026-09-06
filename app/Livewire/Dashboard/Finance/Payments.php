<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Payments\CashSummary;
use App\Data\Payments\ManualPaymentInput;
use App\Data\Payments\RefundInput;
use App\Exceptions\Payments\PaymentConflictException;
use App\Exceptions\Payments\PaymentRuleException;
use App\Exceptions\Payments\StalePaymentStateException;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\SubscriptionEvent;
use App\Services\Payments\AllocationService;
use App\Services\Payments\CashCollectedQuery;
use App\Services\Payments\CustomerPaymentService;
use App\Services\Payments\MoneyFormat;
use App\Services\Payments\RefundService;
use App\Support\Billing\DecimalMath;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Minimal admin page for Phase E1 (the full finance UI is Phase E5): four
 * write operations — Record Manual Payment, Record Refund, Allocate Payment,
 * Allocate Refund — plus a plain per-currency Cash Collected summary for a
 * UTC window. Strict RBAC: `finance.payments.manage` opens the page (route
 * middleware AND mount) and EVERY action re-checks it server-side before the
 * service, which checks again. No dashboard, no charts, no Revenue, no Gross
 * Profit. No PII: subscribers appear as internal ids only. No free text —
 * bounded reference / reason / evidence fields only.
 *
 * Idempotency: each form carries a generated key from the moment it is
 * shown, so a double submit or a retry after a timeout records ONE payment.
 */
#[Title('المدفوعات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Payments extends Component
{
    private const DATETIME = 'Y-m-d\TH:i';

    // ---- Record manual payment ----
    public string $subscriberId = '';

    public string $idempotencyKey = '';

    public string $amount = '';

    public string $currency = '';

    public string $receivedAt = '';

    public string $gatewayPaymentRef = '';

    public string $gatewayFeeAmount = '';

    public string $feeCurrency = '';

    public string $reference = '';

    public string $reasonCode = '';

    public string $evidenceRef = '';

    // ---- Record refund ----
    public string $refundPaymentId = '';

    public string $refundKey = '';

    public string $refundAmount = '';

    public string $refundedAt = '';

    public string $refundReasonCode = '';

    public string $refundGatewayRef = '';

    public string $refundEvidenceRef = '';

    // ---- Allocate payment ----
    public string $allocPaymentId = '';

    public string $allocEventId = '';

    public string $allocAmount = '';

    public string $allocReasonCode = '';

    // ---- Allocate refund ----
    public string $rallocRefundId = '';

    public string $rallocAllocationId = '';

    public string $rallocAmount = '';

    public string $rallocReasonCode = '';

    // ---- Cash window (UTC) ----
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManage();

        $now = CarbonImmutable::now('UTC');
        $this->idempotencyKey = self::freshKey();
        $this->refundKey = self::freshKey();
        $this->receivedAt = $now->format(self::DATETIME);
        $this->refundedAt = $now->format(self::DATETIME);

        if ($this->from === '' || $this->to === '') {
            $this->to = $now->format('Y-m-d');
            $this->from = $now->subDays(29)->format('Y-m-d');
        }
    }

    public function recordPayment(CustomerPaymentService $service): void
    {
        $this->authorizeManage();
        $this->resetErrorBag('payment');
        $this->notice = null;

        try {
            $payment = $service->recordManual(new ManualPaymentInput(
                subscriberId: $this->positiveInt($this->subscriberId, 'معرّف المشترك'),
                idempotencyKey: $this->idempotencyKey,
                amount: $this->amount,
                currency: $this->currency,
                receivedAt: $this->utc($this->receivedAt, 'تاريخ الاستلام'),
                gatewayPaymentRef: self::optional($this->gatewayPaymentRef),
                gatewayFeeAmount: self::optional($this->gatewayFeeAmount),
                feeCurrency: self::optional($this->feeCurrency),
                reference: self::optional($this->reference),
                reasonCode: self::optional($this->reasonCode),
                evidenceRef: self::optional($this->evidenceRef),
            ));
        } catch (PaymentRuleException|PaymentConflictException|InvalidArgumentException $e) {
            $this->addError('payment', $e->getMessage());

            return;
        }

        $this->notice = $payment->wasRecentlyCreated
            ? "سُجِّلت الدفعة #{$payment->id} (succeeded) — {$payment->amount} {$payment->currency}."
            : "الدفعة #{$payment->id} مسجَّلة مسبقًا بنفس المفتاح والحقائق؛ لم يُكتب شيء جديد.";
        $this->reset('subscriberId', 'amount', 'currency', 'gatewayPaymentRef', 'gatewayFeeAmount', 'feeCurrency', 'reference', 'reasonCode', 'evidenceRef');
        $this->idempotencyKey = self::freshKey();
        $this->receivedAt = CarbonImmutable::now('UTC')->format(self::DATETIME);
    }

    public function recordRefund(RefundService $service): void
    {
        $this->authorizeManage();
        $this->resetErrorBag('refund');
        $this->notice = null;

        try {
            $refund = $service->record(new RefundInput(
                customerPaymentId: $this->positiveInt($this->refundPaymentId, 'معرّف الدفعة'),
                idempotencyKey: $this->refundKey,
                amount: $this->refundAmount,
                refundedAt: $this->utc($this->refundedAt, 'تاريخ الاسترداد'),
                reasonCode: $this->refundReasonCode,
                gatewayRefundRef: self::optional($this->refundGatewayRef),
                evidenceRef: self::optional($this->refundEvidenceRef),
            ));
        } catch (PaymentRuleException|PaymentConflictException|InvalidArgumentException $e) {
            $this->addError('refund', $e->getMessage());

            return;
        }

        $this->notice = $refund->wasRecentlyCreated
            ? "سُجِّل الاسترداد #{$refund->id} — {$refund->amount} {$refund->currency} على الدفعة #{$refund->customer_payment_id}."
            : "الاسترداد #{$refund->id} مسجَّل مسبقًا بنفس المفتاح والحقائق؛ لم يُكتب شيء جديد.";
        $this->reset('refundPaymentId', 'refundAmount', 'refundReasonCode', 'refundGatewayRef', 'refundEvidenceRef');
        $this->refundKey = self::freshKey();
        $this->refundedAt = CarbonImmutable::now('UTC')->format(self::DATETIME);
    }

    public function allocatePayment(AllocationService $service): void
    {
        $this->authorizeManage();
        $this->resetErrorBag('allocation');
        $this->notice = null;

        try {
            $allocation = $service->allocatePayment(
                $this->positiveInt($this->allocPaymentId, 'معرّف الدفعة'),
                $this->positiveInt($this->allocEventId, 'معرّف حدث الاشتراك'),
                $this->allocAmount,
                self::optional($this->allocReasonCode),
            );
        } catch (PaymentRuleException|InvalidArgumentException $e) {
            $this->addError('allocation', $e->getMessage());

            return;
        }

        $this->notice = "خُصِّص #{$allocation->id}: {$allocation->amount} {$allocation->currency} من الدفعة #{$allocation->customer_payment_id} لفترة ".$allocation->period_start->toDateString().' → '.$allocation->period_end->toDateString().' (الاشتراك #'.$allocation->subscription_id.').';
        $this->reset('allocEventId', 'allocAmount', 'allocReasonCode');
    }

    public function allocateRefund(AllocationService $service): void
    {
        $this->authorizeManage();
        $this->resetErrorBag('refund_allocation');
        $this->notice = null;

        try {
            $row = $service->allocateRefund(
                $this->positiveInt($this->rallocRefundId, 'معرّف الاسترداد'),
                $this->positiveInt($this->rallocAllocationId, 'معرّف التخصيص'),
                $this->rallocAmount,
                self::optional($this->rallocReasonCode),
            );
        } catch (PaymentRuleException|InvalidArgumentException $e) {
            $this->addError('refund_allocation', $e->getMessage());

            return;
        }

        $this->notice = "نُسب الاسترداد #{$row->customer_refund_id} إلى التخصيص #{$row->payment_allocation_id} بمبلغ {$row->amount} {$row->currency} (سجل #{$row->id}).";
        $this->reset('rallocRefundId', 'rallocAllocationId', 'rallocAmount', 'rallocReasonCode');
    }

    public function render(CashCollectedQuery $cash)
    {
        $this->authorizeManage();

        $payments = CustomerPayment::query()->orderByDesc('id')->limit(25)->get();
        $ids = $payments->pluck('id')->all();

        $refunded = self::centsBy(CustomerRefund::query()->whereIn('customer_payment_id', $ids)->toBase()->selectRaw('customer_payment_id AS k, COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->groupBy('customer_payment_id')->get());
        $allocated = self::centsBy(PaymentAllocation::query()->whereIn('customer_payment_id', $ids)->toBase()->selectRaw('customer_payment_id AS k, COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->groupBy('customer_payment_id')->get());

        $allocPayment = ctype_digit($this->allocPaymentId) ? CustomerPayment::query()->find((int) $this->allocPaymentId) : null;
        $events = $allocPayment === null ? collect() : SubscriptionEvent::query()
            ->where('subscriber_id', $allocPayment->subscriber_id)
            ->whereNotNull('to_period_start')->whereNotNull('to_period_end')
            ->orderByDesc('id')->limit(25)->get();

        $summary = null;
        $windowError = null;

        try {
            [$from, $to] = self::window($this->from, $this->to);
            $summary = $cash->summarise($from, $to);
        } catch (InvalidArgumentException $e) {
            $windowError = $e->getMessage();
        }

        return view('livewire.dashboard.finance.payments', [
            'payments' => $payments,
            'refundedCents' => $refunded,
            'allocatedCents' => $allocated,
            'refunds' => CustomerRefund::query()->orderByDesc('id')->limit(25)->get(),
            'allocations' => PaymentAllocation::query()->orderByDesc('id')->limit(25)->get(),
            'events' => $events,
            'allocPayment' => $allocPayment,
            'summary' => $summary,
            'windowError' => $windowError,
            'maxDays' => CashCollectedQuery::MAX_DAYS,
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [from 00:00, to + 1 day 00:00) UTC
     */
    public static function window(string $from, string $to): array
    {
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');
        } catch (\Throwable) {
            throw new InvalidArgumentException('صيغة التاريخ غير صالحة (YYYY-MM-DD).');
        }

        if ($start === false || $end === false) {
            throw new InvalidArgumentException('صيغة التاريخ غير صالحة (YYYY-MM-DD).');
        }

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('تاريخ النهاية قبل البداية.');
        }

        return [$start, $end->addDay()];
    }

    public static function money(int $cents): string
    {
        return MoneyFormat::of($cents);
    }

    /**
     * @param  iterable<object{k: mixed, s: mixed}>  $rows
     * @return array<int, int>
     */
    private static function centsBy(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->k] = DecimalMath::intFromDb($row->s);
        }

        return $out;
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can(Permission::FinancePaymentsManage->value) ?? false, 403);
    }

    private static function freshKey(): string
    {
        return 'ui:'.Str::uuid()->toString();
    }

    private static function optional(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    private function positiveInt(string $value, string $label): int
    {
        $value = trim($value);

        if (! ctype_digit($value) || (int) $value <= 0) {
            throw new InvalidArgumentException("{$label} يجب أن يكون رقمًا صحيحًا موجبًا.");
        }

        return (int) $value;
    }

    private function utc(string $value, string $label): CarbonImmutable
    {
        try {
            $at = CarbonImmutable::createFromFormat(self::DATETIME, trim($value), 'UTC');
        } catch (\Throwable) {
            $at = false;
        }

        if ($at === false) {
            throw new InvalidArgumentException("{$label} بصيغة غير صالحة (YYYY-MM-DDTHH:MM، UTC).");
        }

        return $at;
    }

    /** @param array<string, CashSummary> $summary */
    public static function hasSummary(?array $summary): bool
    {
        return $summary !== null && $summary !== [];
    }

    /**
     * Thrown by the services when the lifecycle moved under a concurrent
     * actor — surfaced, never retried silently. Kept for completeness: the
     * E1 UI has no transition action, so it is unreachable from this page.
     */
    public function exception(\Throwable $e, callable $stopPropagation): void
    {
        if ($e instanceof StalePaymentStateException) {
            $this->addError('payment', $e->getMessage());
            $stopPropagation();
        }

        if ($e instanceof AuthorizationException) {
            abort(403);
        }
    }
}
