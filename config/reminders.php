<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reminder delivery
    |--------------------------------------------------------------------------
    |
    | The scheduler claims due reminders and delivers them over the channel the
    | reminder came from. Delivery is an EXTERNAL write: the provider send can
    | never join the database transaction, so the guarantee is a bounded
    | at-least-once — never exactly-once (see App\Services\Reminders\
    | ReminderDispatcher).
    |
    */

    // Master switch. Off ⇒ nothing is claimed and nothing is sent.
    'enabled' => filter_var(env('REMINDERS_DELIVERY_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    | How late a reminder may still be delivered. Past this window the occasion
    | has passed and delivering it is worse than not: the reminder becomes
    | terminally `failed` with the bounded reason `too_late`. Configuration, not
    | a constant in domain logic.
    */
    'max_lateness_minutes' => (int) env('REMINDER_MAX_LATENESS_MINUTES', 60),

    // How many due reminders one dispatch run claims.
    'batch' => (int) env('REMINDER_DISPATCH_BATCH', 100),

    /*
    | How long a claim is honoured before the sweeper may recover the reminder.
    | Must comfortably exceed one delivery attempt (HTTP timeout + settlement).
    */
    'lease_seconds' => (int) env('REMINDER_LEASE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Recurring reminders
    |--------------------------------------------------------------------------
    |
    | A recurring reminder is a SCHEDULE (the definition) plus one ORDINARY
    | REMINDER ROW PER OCCURRENCE. The dispatcher, the sweeper, the delivery
    | policy and `messages.reminder_id` know nothing about recurrence, because an
    | occurrence simply IS a reminder — with its own claim, its own attempt
    | budget and its own outbound message.
    |
    | Everything here is CONFIGURATION, not a domain constant: these numbers
    | govern how much future work the platform schedules, and a number that
    | decides that silently from inside a class is the same mistake as an
    | unapproved threshold deciding a release.
    |
    */
    'recurrence' => [

        /*
        | Master switch for the RECURRENCE machinery only. Off ⇒ no schedule is
        | created and nothing is materialised; one-time reminders and the whole
        | delivery path are untouched. It does not disable delivery of
        | occurrences that already exist — those are ordinary reminders, and
        | silently stranding them would be worse than delivering them.
        */
        'enabled' => filter_var(env('REMINDERS_RECURRENCE_ENABLED', true), FILTER_VALIDATE_BOOL),

        /*
        | HORIZON — how far ahead occurrences are created. Both bounds apply and
        | whichever binds first wins: a daily series is limited by the days, a
        | monthly series by neither until the count matters, and a busy weekly
        | series by the count.
        |
        | Reaching a limit is a reason to STOP CREATING, never to delete: an
        | occurrence that exists has an identity, possibly a claim and possibly a
        | delivery record, and removing it to make room would destroy exactly the
        | evidence the phase is built to keep.
        */
        'horizon_days' => (int) env('REMINDERS_HORIZON_DAYS', 30),
        'max_occurrences_per_schedule' => (int) env('REMINDERS_MAX_OCCURRENCES_PER_SCHEDULE', 35),

        // How many ACTIVE recurring series one subscriber may hold at once.
        // At the cap a new schedule is REFUSED; nothing existing is terminated.
        'max_active_schedules_per_subscriber' => (int) env('REMINDERS_MAX_ACTIVE_SCHEDULES', 10),

        // How many schedules one materialisation run walks.
        'materialise_batch' => (int) env('REMINDERS_MATERIALISE_BATCH', 100),

        /*
        | The bound on what `reminder_schedule.list@1` returns. It exists so the
        | model can tell WHICH series the subscriber means days later, and a
        | listing tool with no bound is an unbounded read of subscriber data.
        */
        'list_limit' => (int) env('REMINDERS_SCHEDULE_LIST_LIMIT', 10),

    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp proactive-message policy
    |--------------------------------------------------------------------------
    |
    | A reminder is by definition proactive and may fire long after the
    | subscriber's last message. Outside the platform's customer-service window
    | a free-form text message is not permitted, and the permitted mechanism is
    | an approved template.
    |
    | We do NOT send a free-form message and let the provider reject it. The
    | policy is decided BEFORE dispatch: inside the window ⇒ free-form; outside
    | ⇒ the configured template, or fail closed with `template_required`.
    |
    | `ready` stays false and `name` stays empty until a real template has been
    | approved and configured. No template name is invented in code.
    |
    */
    'whatsapp' => [
        'free_form_window_hours' => (int) env('WHATSAPP_FREE_FORM_WINDOW_HOURS', 24),

        'template' => [
            'ready' => filter_var(env('WHATSAPP_REMINDER_TEMPLATE_READY', false), FILTER_VALIDATE_BOOL),
            'name' => env('WHATSAPP_REMINDER_TEMPLATE_NAME'),
            'language' => env('WHATSAPP_REMINDER_TEMPLATE_LANGUAGE', 'ar'),
        ],
    ],

];
