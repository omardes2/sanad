<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\BillingPeriod;
use App\Enums\UsageDimension;
use App\Models\Plan;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin management of subscription plans. Prices and limits are data — nothing
 * here is hard-coded into application logic. The form exposes the AI-reply
 * limit (the metered dimension in this phase); other dimensions live in the
 * plan's JSON limits and can be surfaced later without a schema change.
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

    public ?int $ai_daily = null;

    public ?int $ai_monthly = null;

    public bool $is_active = true;

    public bool $is_default = false;

    public int $sort_order = 0;

    public function mount(): void
    {
        $this->currency = (string) config('billing.currency', 'USD');
    }

    public function new(): void
    {
        $this->reset(['editingId', 'name', 'slug', 'description', 'price', 'billing_period', 'trial_days', 'ai_daily', 'ai_monthly', 'is_active', 'is_default', 'sort_order']);
        $this->price = '0';
        $this->billing_period = 'monthly';
        $this->is_active = true;
        $this->currency = (string) config('billing.currency', 'USD');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $plan = Plan::findOrFail($id);
        $limit = $plan->limitFor(UsageDimension::AiReply);

        $this->editingId = $plan->id;
        $this->name = $plan->name;
        $this->slug = $plan->slug;
        $this->description = (string) $plan->description;
        $this->price = (string) $plan->price;
        $this->currency = $plan->currency;
        $this->billing_period = $plan->billing_period->value;
        $this->trial_days = $plan->trial_days;
        $this->ai_daily = $limit['daily'] ?? null;
        $this->ai_monthly = $limit['monthly'] ?? null;
        $this->is_active = $plan->is_active;
        $this->is_default = $plan->is_default;
        $this->sort_order = $plan->sort_order;
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
            'ai_daily' => ['nullable', 'integer', 'min:0'],
            'ai_monthly' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $limits = [
            UsageDimension::AiReply->value => [
                'daily' => $this->ai_daily,
                'monthly' => $this->ai_monthly,
                'weight' => 1,
            ],
        ];

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
                'limits' => $limits,
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

    public function render()
    {
        return view('livewire.dashboard.plans', [
            'plans' => Plan::query()->orderBy('sort_order')->orderBy('id')->get(),
            'periods' => BillingPeriod::cases(),
        ]);
    }
}
