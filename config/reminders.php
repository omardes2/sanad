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
