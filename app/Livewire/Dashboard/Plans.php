<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\BillingPeriod;
use App\Enums\PlanFeature;
use App\Enums\PlanFeatureType;
use App\Enums\UsageDimension;
use App\Models\Plan;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin management of subscription plans. Prices, limits and features are DATA,
 * never hard-coded in application logic.
 *
 * The limits/features editor is ENUM-DRIVEN: it iterates UsageDimension::cases()
 * and PlanFeature::cases(), so adding a new metered dimension or a new capability
 * is a one-line enum change — this component and its view need no edits.
 */
#[Title('الباقات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Plans extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public string $price = '0';

    public string $currency = '';

    public string $billing_period = 'monthly';

    public int $trial_days = 0;

    /**
     * Per-dimension limits: [dimension => ['daily' => ?string, 'monthly' => ?string]].
     * Empty string = unlimited (null cap). Bound as strings by Livewire inputs.
     *
     * @var array<string, array{daily: ?string, monthly: ?string}>
     */
    public array $limits = [];

    /**
     * Per-feature entitlements: [feature => bool|string]. Booleans for switches,
     * tier strings for tiered features.
     *
     * @var array<string, bool|string>
     */
    public array $features = [];

    public bool $is_active = true;

    public bool $is_default = false;

    public int $sort_order = 0;

    public function mount(): void
    {
        $this->currency = $this->defaultCurrency();
        $this->limits = $this->blankLimits();
        $this->features = $this->blankFeatures();
    }

    public function new(): void
    {
        $this->reset(['editingId', 'name', 'slug', 'description', 'price', 'billing_period', 'trial_days', 'is_active', 'is_default', 'sort_order']);
        $this->price = '0';
        $this->billing_period = 'monthly';
        $this->is_active = true;
        $this->currency = $this->defaultCurrency();
        $this->limits = $this->blankLimits();
        $this->features = $this->blankFeatures();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $plan = Plan::findOrFail($id);

        $this->editingId = $plan->id;
        $this->name = $plan->name;
        $this->slug = $plan->slug;
        $this->description = (string) $plan->description;
        $this->price = (string) $plan->price;
        $this->currency = $plan->currency;
        $this->billing_period = $plan->billing_period->value;
        $this->trial_days = $plan->trial_days;
        $this->is_active = $plan->is_active;
        $this->is_default = $plan->is_default;
        $this->sort_order = $plan->sort_order;

        $this->limits = $this->blankLimits();
        foreach (UsageDimension::cases() as $dimension) {
            $limit = $plan->limitFor($dimension);
            $this->limits[$dimension->value] = [
                'daily' => isset($limit['daily']) ? (string) $limit['daily'] : null,
                'monthly' => isset($limit['monthly']) ? (string) $limit['monthly'] : null,
            ];
        }

        $this->features = $this->blankFeatures();
        foreach (PlanFeature::cases() as $feature) {
            $this->features[$feature->value] = $plan->featureValue($feature);
        }

        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_period' => ['required', Rule::enum(BillingPeriod::class)],
            'trial_days' => ['required', 'integer', 'min:0'],
            'limits.*.daily' => ['nullable', 'integer', 'min:0'],
            'limits.*.monthly' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $plan = Plan::updateOrCreate(
            ['id' => $this->editingId],
            [
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?: null,
                'price' => $data['price'],
                'currency' => strtoupper($data['currency']),
                'billing_period' => $data['billing_period'],
                'trial_days' => $data['trial_days'],
                'limits' => $this->buildLimits(),
                'features' => $this->buildFeatures(),
                'is_active' => $this->is_active,
                'is_default' => $this->is_default,
                'sort_order' => $data['sort_order'],
            ],
        );

        // At most one default plan.
        if ($plan->is_default) {
            Plan::query()->where('id', '!=', $plan->id)->where('is_default', true)->update(['is_default' => false]);
        }

        $this->showForm = false;
    }

    public function toggleActive(int $id): void
    {
        $plan = Plan::findOrFail($id);
        $plan->forceFill(['is_active' => ! $plan->is_active])->save();
    }

    /**
     * Only dimensions with at least one cap set are stored (an absent dimension
     * = not entitled). Weight stays 1 here; weighting is a future refinement.
     *
     * @return array<string, array{daily: ?int, monthly: ?int, weight: int}>
     */
    private function buildLimits(): array
    {
        $limits = [];

        foreach (UsageDimension::cases() as $dimension) {
            $entry = $this->limits[$dimension->value] ?? [];
            $daily = $this->intOrNull($entry['daily'] ?? null);
            $monthly = $this->intOrNull($entry['monthly'] ?? null);

            if ($daily === null && $monthly === null) {
                continue;
            }

            $limits[$dimension->value] = ['daily' => $daily, 'monthly' => $monthly, 'weight' => 1];
        }

        return $limits;
    }

    /**
     * @return array<string, bool|string>
     */
    private function buildFeatures(): array
    {
        $features = [];

        foreach (PlanFeature::cases() as $feature) {
            $value = $this->features[$feature->value] ?? $feature->default();

            if ($feature->type() === PlanFeatureType::Tier) {
                $features[$feature->value] = in_array($value, $feature->tiers(), true) ? (string) $value : $feature->default();

                continue;
            }

            $features[$feature->value] = (bool) $value;
        }

        return $features;
    }

    private function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * @return array<string, array{daily: ?string, monthly: ?string}>
     */
    private function blankLimits(): array
    {
        $limits = [];

        foreach (UsageDimension::cases() as $dimension) {
            $limits[$dimension->value] = ['daily' => null, 'monthly' => null];
        }

        return $limits;
    }

    /**
     * @return array<string, bool|string>
     */
    private function blankFeatures(): array
    {
        $features = [];

        foreach (PlanFeature::cases() as $feature) {
            $features[$feature->value] = $feature->default();
        }

        return $features;
    }

    private function defaultCurrency(): string
    {
        return (string) config('billing.currency', 'USD');
    }

    public function render()
    {
        return view('livewire.dashboard.plans', [
            'plans' => Plan::query()->orderBy('sort_order')->orderBy('id')->get(),
            'periods' => BillingPeriod::cases(),
            'dimensions' => UsageDimension::cases(),
            'planFeatures' => PlanFeature::cases(),
            'featureTypeTier' => PlanFeatureType::Tier,
        ]);
    }
}
