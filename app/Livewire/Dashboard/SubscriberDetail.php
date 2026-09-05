<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\UsageDimension;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\UsageEngine;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One subscriber: plan, subscription status, trial, AI usage (used / limit /
 * remaining) and manual admin actions (assign plan, activate, suspend, extend).
 */
#[Title('تفاصيل المشترك | سَنَد')]
#[Layout('components.layouts.dashboard')]
class SubscriberDetail extends Component
{
    public User $subscriber;

    public ?int $planId = null;

    public int $extendDays = 30;

    public function mount(User $subscriber): void
    {
        $this->subscriber = $subscriber;
        $this->planId = $subscriber->subscription?->plan_id;
    }

    public function assignPlan(SubscriptionService $service): void
    {
        $this->validate(['planId' => ['required', 'exists:plans,id']]);

        $subscription = $this->subscription()
            ?? Subscription::create(['subscriber_id' => $this->subscriber->id]);

        $service->activate($subscription, Plan::find($this->planId));
        $this->refreshSubscriber();
    }

    public function suspend(SubscriptionService $service): void
    {
        if ($sub = $this->subscription()) {
            $service->suspend($sub);
            $this->refreshSubscriber();
        }
    }

    public function activate(SubscriptionService $service): void
    {
        if ($sub = $this->subscription()) {
            $service->activate($sub);
            $this->refreshSubscriber();
        }
    }

    public function extend(SubscriptionService $service): void
    {
        $this->validate(['extendDays' => ['required', 'integer', 'min:1', 'max:3650']]);

        if ($sub = $this->subscription()) {
            $service->extend($sub, $this->extendDays);
            $this->refreshSubscriber();
        }
    }

    private function subscription(): ?Subscription
    {
        return $this->subscriber->subscription()->first();
    }

    private function refreshSubscriber(): void
    {
        $this->subscriber->refresh()->load('subscription.plan');
    }

    public function render(UsageEngine $usage)
    {
        $this->subscriber->loadMissing('subscription.plan');
        $subscription = $this->subscriber->subscription;
        $entitlement = app(SubscriptionService::class)->entitlement($this->subscriber, UsageDimension::AiReply);
        $used = $usage->usage($this->subscriber, UsageDimension::AiReply);

        return view('livewire.dashboard.subscriber-detail', [
            'subscription' => $subscription,
            'entitlement' => $entitlement,
            'used' => $used,
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }
}
