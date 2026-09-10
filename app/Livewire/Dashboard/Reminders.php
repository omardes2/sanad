<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\ChannelType;
use App\Enums\ReminderFailureReason;
use App\Enums\ReminderStatus;
use App\Services\Reminders\ReminderQuery;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reminders, with everything the delivery phase added and this page never showed.
 *
 * Two permissions, not one. `reminders.view` opens the page and shows the
 * schedule — enough for "did my reminder go out?", which is a support question.
 * `reminders.delivery.view` adds the DELIVERY INTERNALS: the failure reason, the
 * claim and dispatch stamps, the physical attempt count. Those answer engineering
 * questions and are decided server-side here, so an account without the second
 * permission never receives the columns at all rather than being shown a blank.
 *
 * The claim TOKEN is never rendered under either permission. It is a fencing
 * token: displaying it invites reuse, and every operator question it could answer
 * ("is this claimed? by when? is it stale?") is answered by `claimed_at`,
 * `dispatched_at` and a computed staleness flag instead.
 */
#[Title('التذكيرات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Reminders extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $reason = '';

    #[Url]
    public string $channel = '';

    #[Url]
    public string $subscriber_id = '';

    #[Url]
    public string $attempts = '';

    #[Url]
    public string $claimed = '';

    /**
     * Access is gated by `permission.legacy:reminders.view` on the ROUTE, the
     * same as the other pages that pre-date RBAC (conversations, messages,
     * tasks, expenses). Post-RBAC pages check in the component instead; mixing
     * the two conventions on one page would give a legacy admin a link they
     * cannot open, or a check the sibling pages do not have.
     */
    public function mount(): void
    {
        if ($this->from === '' || $this->to === '') {
            $today = CarbonImmutable::today();
            $this->to = $today->addDays(7)->format('Y-m-d');
            $this->from = $today->subDays(ReminderQuery::DEFAULT_DAYS - 1)->format('Y-m-d');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', ...ReminderQuery::FILTERS], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'channel' => $this->channel,
            'subscriber_id' => $this->subscriber_id,
            'attempts' => $this->attempts,
            'claimed' => $this->claimed,
        ];
    }

    public function render()
    {
        $user = auth()->user();
        // Decided here, server-side: without the permission the delivery columns
        // are never sent to the browser at all.
        $showDelivery = $user !== null && ($user->isAdmin() || $user->can(Permission::RemindersDeliveryView->value));

        $error = null;
        $reminders = null;
        $totals = null;

        try {
            [$from, $to] = ReminderQuery::window($this->from, $this->to);
            $query = ReminderQuery::build($from, $to, $this->filters());
            $totals = ReminderQuery::totals($query);
            $reminders = (clone $query)
                ->with('user:id,name')
                ->orderByDesc('remind_at')
                ->orderByDesc('id')
                ->paginate(20);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        return view('livewire.dashboard.reminders', [
            'reminders' => $reminders,
            'totals' => $totals,
            'error' => $error,
            'showDelivery' => $showDelivery,
            'maxDays' => ReminderQuery::MAX_DAYS,
            'statuses' => ReminderStatus::options(),
            'reasons' => ReminderFailureReason::options(),
            'channels' => ChannelType::options(),
        ]);
    }
}
