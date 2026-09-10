<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Reminder;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One reminder: its schedule, its delivery outcome, and — for an account with
 * `reminders.delivery.view` — the internals that explain the outcome.
 *
 * The claim token is not rendered here either. `claimed_at`, `dispatched_at` and
 * `isStaleClaim()` answer every operational question about the claim without
 * handing anyone the fence that protects it.
 */
#[Title('تذكير | سَنَد')]
#[Layout('components.layouts.dashboard')]
class ReminderDetail extends Component
{
    public Reminder $reminder;

    /** Access is gated by `permission.legacy:reminders.view` on the route. */
    public function mount(Reminder $reminder): void
    {
        $this->reminder = $reminder;
    }

    public function render()
    {
        $user = auth()->user();
        $showDelivery = $user !== null && ($user->isAdmin() || $user->can(Permission::RemindersDeliveryView->value));

        return view('livewire.dashboard.reminder-detail', [
            'reminder' => $this->reminder->loadMissing(['user:id,name', 'deliveredMessage:id,reminder_id,delivery_status,created_at']),
            'showDelivery' => $showDelivery,
            'reason' => $this->reminder->failureReason(),
            // The raw value, so a legacy row that never held an enum value is
            // still shown rather than silently rendered as "no reason".
            'rawError' => $this->reminder->last_error,
            'maxLateness' => (int) config('reminders.max_lateness_minutes', 60),
            // Configuration, so an operator can see whether the proactive-message
            // policy CAN use a template at all. Deliberately not a prediction of
            // what the dispatcher would decide for this reminder: that decision
            // needs a resolved recipient and is re-made at dispatch time, and a
            // second implementation of the same rule here could disagree with
            // the only one that counts.
            'templateConfigured' => (bool) config('reminders.whatsapp.template.ready', false)
                && trim((string) config('reminders.whatsapp.template.name', '')) !== '',
            'freeFormWindowHours' => (int) config('reminders.whatsapp.free_form_window_hours', 24),
        ]);
    }
}
