<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\UsageDimension;
use App\Exceptions\Billing\StaleSubscriptionStateException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\UsageEngine;
use App\Support\Billing\SubscriptionStateToken;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One subscriber: plan, subscription status, trial, AI usage (used / limit /
 * remaining) and manual admin actions (assign plan, activate, suspend, extend).
 *
 * Phase E0: every action carries the SubscriptionStateToken captured when the
 * page was rendered; if another admin changed the subscription in between the
 * service refuses (nothing written) and the page shows the conflict.
 */
#[Title('تفاصيل المشترك | سَنَد')]
#[Layout('components.layouts.dashboard')]
class SubscriberDetail extends Component
{
    public User $subscriber;

    public ?int $planId = null;

    public int $extendDays = 30;

    /** State the admin is looking at; every mutation must still match it. */
    public string $stateToken = SubscriptionStateToken::NONE;

    public function mount(User $subscriber): void
    {
        $this->subscriber = $subscriber;
        $this->planId = $subscriber->subscription?->plan_id;
        $this->stateToken = $this->currentToken();
    }

    public function assignPlan(SubscriptionService $service): void
    {
        $this->validate(['planId' => ['required', 'exists:plans,id']]);

        // Creation + activation happen inside the service's transaction (E0:
        // history event + audit atomic with the state; never a bare row).
        $this->guarded(fn () => $service->activateFor($this->subscriber, Plan::findOrFail($this->planId), $this->stateToken));
    }

    public function suspend(SubscriptionService $service): void
    {
        if ($sub = $this->subscription()) {
            $this->guarded(fn () => $service->suspend($sub, $this->stateToken));
        }
    }

    public function activate(SubscriptionService $service): void
    {
        if ($sub = $this->subscription()) {
            $this->guarded(fn () => $service->activate($sub, $this->stateToken));
        }
    }

    public function extend(SubscriptionService $service): void
    {
        $this->validate(['extendDays' => ['required', 'integer', 'min:1', 'max:3650']]);

        if ($sub = $this->subscription()) {
            $this->guarded(fn () => $service->extend($sub, $this->extendDays, $this->stateToken));
        }
    }

    /** Run a mutation with the page's token; on a stale conflict, write nothing and show it. */
    private function guarded(callable $mutation): void
    {
        try {
            $mutation();
        } catch (StaleSubscriptionStateException) {
            $this->refreshSubscriber();
            $this->addError('state', 'تغيّرت حالة الاشتراك منذ فتح الصفحة (تعديل متزامن). لم يُنفَّذ شيء — راجع الحالة الحالية وقرّر مجددًا.');

            return;
        }

        $this->refreshSubscriber();
    }

    private function subscription(): ?Subscription
    {
        return $this->subscriber->subscription()->first();
    }

    private function refreshSubscriber(): void
    {
        $this->subscriber->refresh()->load('subscription.plan');
        $this->stateToken = $this->currentToken();
    }

    private function currentToken(): string
    {
        $subscription = $this->subscriber->subscription()->first();

        return $subscription === null ? SubscriptionStateToken::NONE : SubscriptionStateToken::for($subscription);
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
