<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Payments\CashSummary;
use App\Data\Payments\ManualPaymentInput;
use App\Enums\CustomerPaymentEventType;
use App\Livewire\Dashboard\Finance\Concerns\HandlesPaymentActions;
use App\Models\CustomerPayment;
use App\Services\Payments\CashCollectedQuery;
use App\Services\Payments\CustomerPaymentService;
use App\Services\Payments\PaymentLedgerView;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Payments list (Phase E1 → E5.2a operational UI). `finance.payments.manage`
 * on the route, the mount and every action. Read-only render: filters
 * (allowlisted, bounded, kept in the URL, page reset on change), 25 rows per
 * page in a stable indexed order (id desc), the window cash summary from
 * CashCollectedQuery, and the Record Manual Payment form with one attempt
 * key per attempt. Refunds, allocations and lifecycle actions live on the
 * detail pages. No names, e-mails or phones — ids only. Every figure is Cash
 * Collected, never revenue or profit.
 */
#[Title('المدفوعات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Payments extends Component
{
    use HandlesPaymentActions;
    use WithPagination;

    public const PER_PAGE = 25;

    public const CURRENCIES = ['USD', 'ILS', 'EUR', 'GBP', 'JOD', 'SAR', 'AED'];

    // ---- filters (URL) ---------------------------------------------------------------
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $currency = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $subscriber = '';

    #[Url]
    public string $gateway = '';

    #[Url]
    public string $fee = '';

    // ---- record manual payment ---------------------------------------------------------
    public string $subscriberId = '';

    public string $idempotencyKey = '';

    public string $amount = '';

    public string $paymentCurrency = '';

    public string $receivedAt = '';

    public string $gatewayPaymentRef = '';

    public string $gatewayFeeAmount = '';

    public string $feeCurrency = '';

    public string $reference = '';

    public string $reasonCode = '';

    public string $evidenceRef = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManage();

        $now = CarbonImmutable::now('UTC');
        $this->idempotencyKey = self::freshKey(); // one attempt key; rotates only after success
        $this->receivedAt = $now->format(self::DATETIME);

        if ($this->from === '' || $this->to === '') {
            $this->to = $now->format('Y-m-d');
            $this->from = $now->subDays(29)->format('Y-m-d');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'currency', 'status', 'subscriber', 'gateway', 'fee'], true)) {
            $this->resetPage();
        }
    }

    public function recordPayment(CustomerPaymentService $service): void
    {
        $ok = $this->attempt('payment', $this->idempotencyKey, function () use ($service): void {
            $payment = $service->recordManual(new ManualPaymentInput(
                subscriberId: $this->positiveInt($this->subscriberId, 'معرّف المشترك'),
                idempotencyKey: $this->idempotencyKey,
                amount: $this->amount,
                currency: $this->paymentCurrency,
                receivedAt: $this->utc($this->receivedAt, 'تاريخ الاستلام'),
                gatewayPaymentRef: self::optional($this->gatewayPaymentRef),
                gatewayFeeAmount: self::optional($this->gatewayFeeAmount),
                feeCurrency: self::optional($this->feeCurrency),
                reference: self::optional($this->reference),
                reasonCode: self::optional($this->reasonCode),
                evidenceRef: self::optional($this->evidenceRef),
            ));

            $this->notice = $payment->wasRecentlyCreated
                ? "سُجِّلت الدفعة #{$payment->id} (succeeded) — {$payment->amount} {$payment->currency}."
                : "الدفعة #{$payment->id} مسجَّلة مسبقًا بنفس المفتاح والحقائق؛ لم يُكتب شيء جديد.";
        });

        if ($ok) {
            $this->reset('subscriberId', 'amount', 'paymentCurrency', 'gatewayPaymentRef', 'gatewayFeeAmount', 'feeCurrency', 'reference', 'reasonCode', 'evidenceRef');
            $this->idempotencyKey = self::freshKey(); // success ⇒ the next attempt gets its own key
            $this->receivedAt = CarbonImmutable::now('UTC')->format(self::DATETIME);
        }
    }

    public function render(CashCollectedQuery $cash, PaymentLedgerView $ledger)
    {
        $this->authorizeManage();

        $filters = $this->filters();
        $summary = null;
        $windowError = null;
        $window = null;

        try {
            $window = self::window($this->from, $this->to);
            $summary = $cash->summarise($window[0], $window[1]);
        } catch (InvalidArgumentException $e) {
            $windowError = $e->getMessage();
        }

        $query = CustomerPayment::query()->orderByDesc('id');

        if ($window !== null) {
            $query->where('received_at', '>=', $window[0]->format(CustomerPayment::TIMESTAMP_FORMAT))->where('received_at', '<', $window[1]->format(CustomerPayment::TIMESTAMP_FORMAT));
        } else {
            $query->whereRaw('1 = 0'); // an invalid window lists nothing — never "everything"
        }
        if ($filters['currency'] !== null) {
            $query->where('currency', $filters['currency']);
        }
        if ($filters['status'] !== null) {
            $query->where('current_status', $filters['status']);
        }
        if ($filters['subscriber'] !== null) {
            $query->where('subscriber_id', $filters['subscriber']);
        }
        if ($filters['gateway'] !== null) {
            $query->where('gateway', $filters['gateway']);
        }
        if ($filters['fee'] === 'known') {
            $query->whereNotNull('gateway_fee_amount');
        } elseif ($filters['fee'] === 'unknown') {
            $query->whereNull('gateway_fee_amount');
        }

        $payments = $query->paginate(self::PER_PAGE);
        $sums = $ledger->paymentSums($payments->getCollection()->pluck('id')->all());

        return view('livewire.dashboard.finance.payments', [
            'payments' => $payments,
            'refundedCents' => $sums['refunded'],
            'allocatedCents' => $sums['allocated'],
            'summary' => $summary,
            'windowError' => $windowError,
            'filters' => $filters,
            'statuses' => array_map(static fn (CustomerPaymentEventType $t): string => $t->value, CustomerPaymentEventType::cases()),
            'currencies' => self::CURRENCIES,
            'maxDays' => CashCollectedQuery::MAX_DAYS,
        ]);
    }

    /**
     * Allowlisted, normalised filters; anything outside the allowlist is ignored (null).
     *
     * @return array{currency: ?string, status: ?string, subscriber: ?int, gateway: ?string, fee: ?string}
     */
    public function filters(): array
    {
        $currency = strtoupper(trim($this->currency));
        $status = trim($this->status);
        $subscriber = trim($this->subscriber);
        $gateway = strtolower(trim($this->gateway));
        $fee = strtolower(trim($this->fee));

        return [
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
            'status' => CustomerPaymentEventType::tryFrom($status)?->value,
            'subscriber' => preg_match('/^\d{1,19}$/', $subscriber) === 1 ? (int) $subscriber : null,
            'gateway' => preg_match('/^[a-z0-9_-]{1,32}$/', $gateway) === 1 ? $gateway : null,
            'fee' => in_array($fee, ['known', 'unknown'], true) ? $fee : null,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [from 00:00, to + 1 day 00:00) UTC, bounded to CashCollectedQuery::MAX_DAYS
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

        if ($start->diffInDays($end->addDay()) > CashCollectedQuery::MAX_DAYS) {
            throw new InvalidArgumentException('النطاق الأقصى '.CashCollectedQuery::MAX_DAYS.' يومًا.');
        }

        return [$start, $end->addDay()];
    }

    public static function money(int $cents): string
    {
        return PaymentLedgerView::money($cents);
    }

    /** @param array<string, CashSummary> $summary */
    public static function hasSummary(?array $summary): bool
    {
        return $summary !== null && $summary !== [];
    }

    protected function refreshRecord(): void
    {
        // The list has no record-bound action; the attempt key is the only state.
    }
}
