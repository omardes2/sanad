<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Reconciliation\CostInvoiceInput;
use App\Enums\CostComponent;
use App\Enums\CostInvoiceEventType;
use App\Livewire\Dashboard\Finance\Concerns\HandlesReconciliationActions;
use App\Models\CostInvoice;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\ReconciledCostQuery;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cost invoices list (Phase E2 → E5.2b operational UI). `finance.reconcile` on
 * the route, the mount and every action. Read-only render: allowlisted,
 * bounded, URL-kept filters (component · counterparty key · status · period
 * month window UTC ≤ 13 months · currency as a secondary narrowing · exact
 * invoice_ref), 25 rows per page in a stable indexed order (id desc), and the
 * Record Invoice form with one attempt key per attempt (the service's own
 * idempotency key). Lines, lifecycle and evidence live on the detail page.
 * Counterparties are stable keys — never names. A confirmed invoice is
 * evidence only, never actual cost.
 */
#[Title('فواتير التكلفة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class CostInvoices extends Component
{
    use HandlesReconciliationActions;
    use WithPagination;

    public const PER_PAGE = 25;

    public const CURRENCIES = ['USD', 'ILS', 'EUR', 'GBP', 'JOD', 'SAR', 'AED'];

    // ---- filters (URL) ---------------------------------------------------------------
    #[Url]
    public string $component = '';

    #[Url]
    public string $counterparty = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $fromMonth = '';

    #[Url]
    public string $toMonth = '';

    #[Url]
    public string $currency = '';

    #[Url]
    public string $ref = '';

    // ---- record invoice (draft) ----------------------------------------------------------
    public string $invKey = '';

    public string $invComponent = 'provider';

    public string $invCounterparty = '';

    public string $invRef = '';

    public string $invIssuedAt = '';

    public string $invPeriodStart = '';

    public string $invPeriodEnd = '';

    public string $invCurrency = '';

    public string $invTotal = '';

    public string $invEvidence = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManage();
        $now = CarbonImmutable::now('UTC');
        $this->invKey = self::freshKey();
        $this->invIssuedAt = $now->format('Y-m-d');
        $this->invPeriodStart = $now->startOfMonth()->format('Y-m-d');
        $this->invPeriodEnd = $now->startOfMonth()->addMonth()->format('Y-m-d');

        if ($this->fromMonth === '' || $this->toMonth === '') {
            $this->toMonth = $now->format('Y-m');
            $this->fromMonth = $now->subMonths(2)->format('Y-m');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['component', 'counterparty', 'status', 'fromMonth', 'toMonth', 'currency', 'ref'], true)) {
            $this->resetPage();
        }
    }

    public function recordInvoice(CostInvoiceService $service): void
    {
        $ok = $this->attempt('invoice', $this->invKey, function () use ($service): void {
            $invoice = $service->recordDraft(new CostInvoiceInput(
                component: $this->invComponent, counterpartyKey: $this->invCounterparty, idempotencyKey: $this->invKey,
                issuedAt: $this->utcDate($this->invIssuedAt, 'تاريخ الإصدار'), periodStart: $this->utcDate($this->invPeriodStart, 'بداية الفترة'), periodEnd: $this->utcDate($this->invPeriodEnd, 'نهاية الفترة'),
                currency: $this->invCurrency, totalAmount: $this->invTotal, invoiceRef: self::optional($this->invRef), evidenceRef: self::optional($this->invEvidence),
            ));

            $this->notice = $invoice->wasRecentlyCreated
                ? "سُجِّلت الفاتورة #{$invoice->id} كمسودة (token {$invoice->stateToken()}) — {$invoice->total_amount} {$invoice->currency}. أضف الأسطر ثم أكّد من صفحة التفاصيل."
                : "الفاتورة #{$invoice->id} مسجَّلة مسبقًا بنفس المفتاح والحقائق؛ لم يُكتب شيء جديد.";
        });

        if ($ok) {
            $this->reset('invCounterparty', 'invRef', 'invTotal', 'invEvidence', 'invCurrency');
            $this->invKey = self::freshKey(); // success ⇒ the next attempt gets its own key
        }
    }

    public function render()
    {
        $this->authorizeManage();
        $filters = $this->filters();
        $windowError = null;
        $window = null;

        try {
            $window = self::window($this->fromMonth, $this->toMonth);
        } catch (InvalidArgumentException $e) {
            $windowError = $e->getMessage();
        }

        $query = CostInvoice::query()->orderByDesc('id');

        if ($window !== null) {
            $query->where('period_start', '>=', $window[0]->format('Y-m-d H:i:s'))->where('period_start', '<', $window[1]->format('Y-m-d H:i:s'));
        } else {
            $query->whereRaw('1 = 0'); // an invalid window lists nothing — never "everything"
        }
        if ($filters['component'] !== null) {
            $query->where('component', $filters['component']);
        }
        if ($filters['counterparty'] !== null) {
            $query->where('counterparty_key', $filters['counterparty']);
        }
        if ($filters['status'] !== null) {
            $query->where('current_status', $filters['status']);
        }
        if ($filters['currency'] !== null) {
            $query->where('currency', $filters['currency']); // secondary narrowing only (no index of its own)
        }
        if ($filters['ref'] !== null) {
            $query->where('invoice_ref', $filters['ref']); // exact match on the (counterparty_key, invoice_ref) unique index
        }

        return view('livewire.dashboard.finance.cost-invoices', [
            'invoices' => $query->paginate(self::PER_PAGE),
            'windowError' => $windowError,
            'filters' => $filters,
            'components' => array_map(static fn (CostComponent $c): string => $c->value, CostComponent::cases()),
            'statuses' => array_map(static fn (CostInvoiceEventType $t): string => $t->value, CostInvoiceEventType::cases()),
            'currencies' => self::CURRENCIES,
            'maxMonths' => ReconciledCostQuery::MAX_MONTHS,
        ]);
    }

    /**
     * Allowlisted, normalised filters; anything outside the allowlist is ignored (null).
     *
     * @return array{component: ?string, counterparty: ?string, status: ?string, currency: ?string, ref: ?string}
     */
    public function filters(): array
    {
        $component = strtolower(trim($this->component));
        $counterparty = trim($this->counterparty);
        $status = strtolower(trim($this->status));
        $currency = strtoupper(trim($this->currency));
        $ref = trim($this->ref);

        return [
            'component' => CostComponent::tryFrom($component)?->value,
            'counterparty' => preg_match('/^[\p{L}\p{N}_\-.:\/#]{1,64}$/u', $counterparty) === 1 ? $counterparty : null,
            'status' => CostInvoiceEventType::tryFrom($status)?->value,
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
            'ref' => preg_match('/^[\p{L}\p{N}_\-.:\/#]{1,191}$/u', $ref) === 1 ? $ref : null,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [first day of fromMonth, first day after toMonth) UTC, ≤ ReconciledCostQuery::MAX_MONTHS
     */
    public static function window(string $fromMonth, string $toMonth): array
    {
        try {
            [$from] = ReconciliationRules::month($fromMonth);
            [, $to] = ReconciliationRules::month($toMonth);
        } catch (\Throwable) {
            throw new InvalidArgumentException('صيغة الشهر غير صالحة (YYYY-MM).');
        }

        if ($to <= $from) {
            throw new InvalidArgumentException('نهاية النطاق يجب أن تكون بعد بدايته.');
        }

        if ($from->diffInMonths($to) > ReconciledCostQuery::MAX_MONTHS) {
            throw new InvalidArgumentException('النطاق الأقصى '.ReconciledCostQuery::MAX_MONTHS.' شهرًا.');
        }

        return [$from, $to];
    }

    protected function refreshRecord(): void
    {
        // The list has no record-bound action; the attempt key is the only state.
    }
}
