<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\UsageEvent;
use App\Services\Usage\UsageQuery;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Usage ledger browser (Phase C2). Strict RBAC: `usage.view` opens the page;
 * cost columns and money totals are rendered ONLY when the account holds
 * `usage.view_costs` (decided here, server-side — the browser never receives
 * them otherwise); the CSV link appears only with `usage.export`.
 *
 * Totals: priced rows are summed; unpriced rows are counted by reason and
 * shown as "unknown cost", never as zero.
 */
#[Title('الاستخدام | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Usage extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $provider = '';

    #[Url]
    public string $model = '';

    #[Url]
    public string $subscriber_id = '';

    #[Url]
    public string $outcome = '';

    #[Url]
    public string $operation = '';

    #[Url]
    public string $cost = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::UsageView->value) ?? false, 403);

        if ($this->from === '' || $this->to === '') {
            $today = CarbonImmutable::today();
            $this->to = $today->format('Y-m-d');
            $this->from = $today->subDays(UsageQuery::DEFAULT_DAYS - 1)->format('Y-m-d');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', ...UsageQuery::FILTERS], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'subscriber_id' => $this->subscriber_id,
            'outcome' => $this->outcome,
            'operation' => $this->operation,
            'cost' => $this->cost,
        ];
    }

    public function render()
    {
        $user = auth()->user();
        $showCosts = $user?->can(Permission::UsageViewCosts->value) ?? false;
        $canExport = $user?->can(Permission::UsageExport->value) ?? false;
        $error = null;
        $events = null;
        $totals = null;

        try {
            [$from, $to] = UsageQuery::window($this->from, $this->to);
            $query = UsageQuery::build($from, $to, $this->filters());
            $totals = UsageQuery::totals($query);
            $events = (clone $query)->orderByDesc('occurred_at')->orderByDesc('id')->paginate(25);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        return view('livewire.dashboard.usage', [
            'events' => $events,
            'totals' => $totals,
            'error' => $error,
            'showCosts' => $showCosts,
            'canExport' => $canExport,
            'providers' => UsageEvent::query()->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider')->all(),
            'exportUrl' => $canExport ? route('dashboard.usage.export', array_filter(['from' => $this->from, 'to' => $this->to] + $this->filters(), static fn ($v) => $v !== '')) : null,
            'maxDays' => UsageQuery::MAX_DAYS,
        ]);
    }
}
