<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Services\Payments\CashCollectedQuery;
use App\Services\Payments\PaymentLedgerView;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Refunds list (Phase E5.2a) — `finance.payments.manage`, read-only render:
 * UTC window on refunded_at (bounded), currency and payment filters kept in
 * the URL, 25 rows per page in id-desc order, ids only.
 */
#[Title('الاستردادات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Refunds extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $currency = '';

    #[Url]
    public string $payment = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::FinancePaymentsManage->value) ?? false, 403);

        if ($this->from === '' || $this->to === '') {
            $now = CarbonImmutable::now('UTC');
            $this->to = $now->format('Y-m-d');
            $this->from = $now->subDays(29)->format('Y-m-d');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'currency', 'payment'], true)) {
            $this->resetPage();
        }
    }

    public function render(PaymentLedgerView $ledger)
    {
        abort_unless(auth()->user()?->can(Permission::FinancePaymentsManage->value) ?? false, 403);

        $windowError = null;
        $query = CustomerRefund::query()->orderByDesc('id');

        try {
            [$from, $to] = Payments::window($this->from, $this->to);
            $query->where('refunded_at', '>=', $from->format(CustomerPayment::TIMESTAMP_FORMAT))->where('refunded_at', '<', $to->format(CustomerPayment::TIMESTAMP_FORMAT));
        } catch (InvalidArgumentException $e) {
            $windowError = $e->getMessage();
            $query->whereRaw('1 = 0'); // an invalid window lists nothing
        }

        $currency = strtoupper(trim($this->currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $query->where('currency', $currency);
        }
        $payment = trim($this->payment);
        if (preg_match('/^\d{1,19}$/', $payment) === 1) {
            $query->where('customer_payment_id', (int) $payment);
        }

        $refunds = $query->paginate(Payments::PER_PAGE);

        return view('livewire.dashboard.finance.refunds', [
            'refunds' => $refunds,
            'allocatedCents' => $ledger->refundAllocated($refunds->getCollection()->pluck('id')->all()),
            'windowError' => $windowError,
            'currencies' => Payments::CURRENCIES,
            'maxDays' => CashCollectedQuery::MAX_DAYS,
        ]);
    }
}
