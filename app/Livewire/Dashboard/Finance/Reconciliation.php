<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Enums\CostComponent;
use App\Enums\CostCoverageStatus;
use App\Enums\ReconciliationSource;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Livewire\Dashboard\Finance\Concerns\HandlesReconciliationActions;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Services\Reconciliation\CostReconciliationService;
use App\Services\Reconciliation\ReconciledCostQuery;
use App\Services\Reconciliation\ReconciliationLedgerView;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reconciliation scope list (Phase E2 → E5.2b operational UI), same route as
 * the former single-page E2 form. `finance.reconcile` on the route, the mount
 * and every action. Read-only render of CHEAP stored data only: the scope
 * identity, the current pointer, the current reconciliation's source /
 * status, frozen base, Σ adjustments (one grouped query per page), Adjusted,
 * the frozen ledger snapshot and the frozen variance. NO live ledger capture
 * per row: the live ledger status is `NOT CHECKED` until the user runs
 * CHECK LEDGER for ONE scope (read-only, on demand, no cache, no snapshot
 * materialisation). Filters allowlisted, bounded (≤ 13 months), URL-kept;
 * 25 rows per page. Every write lives on the scope detail page.
 */
#[Title('تسوية التكلفة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Reconciliation extends Component
{
    use HandlesReconciliationActions;
    use WithPagination;

    public const PER_PAGE = 25;

    public const STATUSES = ['not_reconciled', 'reconciled', 'confirmed_zero'];

    #[Url]
    public string $fromMonth = '';

    #[Url]
    public string $toMonth = '';

    #[Url]
    public string $component = '';

    #[Url]
    public string $counterparty = '';

    #[Url]
    public string $currency = '';

    #[Url]
    public string $status = '';

    /**
     * On-demand live ledger checks of THIS page view, keyed by scope id — page
     * state, not a cache: nothing is stored server-side, a re-render does not
     * re-run them, a filter change clears them.
     *
     * @var array<int, array{at: string, status: string, flags: list<string>}>
     */
    public array $ledgerChecks = [];

    // ---- start a reconciliation for a scope that has no row yet ----
    public string $newComponent = 'provider';

    public string $newCounterparty = '';

    public string $newMonth = '';

    public string $newCurrency = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManage();
        $now = CarbonImmutable::now('UTC');
        $this->newMonth = $now->subMonth()->format('Y-m');

        if ($this->fromMonth === '' || $this->toMonth === '') {
            $this->toMonth = $now->format('Y-m');
            $this->fromMonth = $now->subMonths(2)->format('Y-m');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['fromMonth', 'toMonth', 'component', 'counterparty', 'currency', 'status'], true)) {
            $this->resetPage();
            $this->ledgerChecks = [];
        }
    }

    /** CHECK LEDGER — one scope, read-only, on demand: the same describe() the close preflight uses, never per row on render. */
    public function checkLedger(int $scopeId, ReconciledCostQuery $query): void
    {
        $this->authorizeManage();
        $scope = CostReconciliationScope::query()->findOrFail($scopeId);
        $summary = $query->describe($scope);

        $this->ledgerChecks[$scopeId] = [
            'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            'status' => $summary->reconciliationId === null ? 'NOT RECONCILED — nothing to compare' : ($summary->ledgerMoved ? 'LEDGER MOVED SINCE RECONCILIATION' : 'UNCHANGED SINCE RECONCILIATION'),
            'flags' => $summary->flags,
        ];
    }

    /** Open the detail page for a scope identity that has no row yet (the row is created by the service on the first reconciliation). */
    public function startScope(): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();

        try {
            $component = ReconciliationRules::component($this->newComponent);
            $counterparty = ReconciliationRules::requiredRef($this->newCounterparty, 64, 'counterparty_key');
            [$start] = ReconciliationRules::month($this->newMonth);
            $currency = ReconciliationRules::currency($this->newCurrency, 'currency');
        } catch (ReconciliationRuleException $e) {
            $this->addError('scope.rule', $e->rule.' — '.$e->getMessage());

            return;
        }

        $existing = CostReconciliationScope::query()->where('component', $component->value)->where('counterparty_key', $counterparty)
            ->where('period_start', $start->format('Y-m-d H:i:s'))->where('currency', $currency)->first();

        $this->redirectRoute($existing ? 'dashboard.finance.reconciliation.show' : 'dashboard.finance.reconciliation.new', $existing ? ['scope' => $existing->id] : [
            'component' => $component->value, 'counterparty' => $counterparty, 'month' => $start->format('Y-m'), 'currency' => $currency,
        ]);
    }

    public function render(ReconciliationLedgerView $ledger)
    {
        $this->authorizeManage();
        $filters = $this->filters();
        $windowError = null;
        $window = null;

        try {
            $window = CostInvoices::window($this->fromMonth, $this->toMonth);
        } catch (InvalidArgumentException $e) {
            $windowError = $e->getMessage();
        }

        $query = CostReconciliationScope::query()->orderByDesc('id');

        if ($window !== null) {
            $query->where('period_start', '>=', $window[0]->format('Y-m-d H:i:s'))->where('period_start', '<', $window[1]->format('Y-m-d H:i:s'));
        } else {
            $query->whereRaw('1 = 0');
        }
        if ($filters['component'] !== null) {
            $query->where('component', $filters['component']);
        }
        if ($filters['counterparty'] !== null) {
            $query->where('counterparty_key', $filters['counterparty']);
        }
        if ($filters['currency'] !== null) {
            $query->where('currency', $filters['currency']);
        }
        if ($filters['status'] === 'not_reconciled') {
            $query->whereNull('current_reconciliation_id');
        } elseif ($filters['status'] === 'confirmed_zero') {
            $query->whereIn('current_reconciliation_id', CostReconciliation::query()->select('id')->where('source', ReconciliationSource::ConfirmedZero->value));
        } elseif ($filters['status'] === 'reconciled') {
            $query->whereIn('current_reconciliation_id', CostReconciliation::query()->select('id')->where('source', '!=', ReconciliationSource::ConfirmedZero->value));
        }

        $scopes = $query->paginate(self::PER_PAGE);
        $currentIds = $scopes->getCollection()->pluck('current_reconciliation_id')->filter()->values()->all();
        $current = CostReconciliation::query()->whereIn('id', $currentIds)->get()->keyBy('id');
        $adjustments = $ledger->adjustments($currentIds);

        $rows = [];
        foreach ($scopes as $scope) {
            $rec = $scope->current_reconciliation_id === null ? null : $current->get($scope->current_reconciliation_id);
            $rows[] = self::row($scope, $rec, $adjustments[$scope->current_reconciliation_id] ?? 0);
        }

        return view('livewire.dashboard.finance.reconciliation', [
            'scopes' => $scopes,
            'rows' => $rows,
            'windowError' => $windowError,
            'filters' => $filters,
            'components' => array_map(static fn (CostComponent $c): string => $c->value, CostComponent::cases()),
            'statuses' => self::STATUSES,
            'currencies' => CostInvoices::CURRENCIES,
            'maxMonths' => ReconciledCostQuery::MAX_MONTHS,
        ]);
    }

    /**
     * Frozen, stored figures only — never a ledger capture.
     *
     * @return array<string, mixed>
     */
    public static function row(CostReconciliationScope $scope, ?CostReconciliation $rec, int $adjustmentsScaled): array
    {
        if ($rec === null) {
            return ['scope' => $scope, 'rec' => null, 'status' => 'NOT RECONCILED', 'base' => null, 'adjustments' => ReconciliationRules::format(0), 'adjusted' => null, 'variance' => null, 'varianceStatus' => 'UNKNOWN', 'coverage' => null];
        }

        $base = CostReconciliationService::scaledOf((string) $rec->reconciled_amount);
        $known = CostReconciliationService::scaledOf((string) $rec->calculated_known_amount);
        $variance = $rec->cost_coverage_status->allowsVariance();

        return [
            'scope' => $scope,
            'rec' => $rec,
            'status' => $rec->source === ReconciliationSource::ConfirmedZero ? 'CONFIRMED ZERO' : 'RECONCILED',
            'base' => $rec->source === ReconciliationSource::ConfirmedZero ? 'CONFIRMED ZERO' : ReconciliationRules::format($base),
            'adjustments' => ReconciliationRules::format($adjustmentsScaled),
            'adjusted' => ReconciliationRules::format($base + $adjustmentsScaled),
            'variance' => $variance ? ReconciliationRules::format($base + $adjustmentsScaled - $known) : null,
            'varianceStatus' => $variance ? 'KNOWN (frozen)' : ($rec->cost_coverage_status === CostCoverageStatus::NoProducer ? 'UNKNOWN (NO PRODUCER)' : 'UNKNOWN (PARTIAL CALCULATED COVERAGE)'),
            'coverage' => $rec->cost_coverage_status->label(),
        ];
    }

    /**
     * @return array{component: ?string, counterparty: ?string, currency: ?string, status: ?string}
     */
    public function filters(): array
    {
        $component = strtolower(trim($this->component));
        $counterparty = trim($this->counterparty);
        $currency = strtoupper(trim($this->currency));
        $status = strtolower(trim($this->status));

        return [
            'component' => CostComponent::tryFrom($component)?->value,
            'counterparty' => preg_match('/^[\p{L}\p{N}_\-.:\/#]{1,64}$/u', $counterparty) === 1 ? $counterparty : null,
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
            'status' => in_array($status, self::STATUSES, true) ? $status : null,
        ];
    }

    protected function refreshRecord(): void
    {
        // The list has no record-bound write action.
    }
}
