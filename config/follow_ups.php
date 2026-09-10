<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Follow-Up Until Done
    |--------------------------------------------------------------------------
    |
    | A follow-up is NOT a reminder. A reminder fires at a time the subscriber
    | chose and is finished when it is delivered. A follow-up is an OPEN LOOP
    | whose outcome Sanad does not know: it asks, and it is finished only when
    | the subscriber answers, cancels, the linked task is completed, or the
    | bounded ask budget runs out.
    |
    | Each physical ask is an ORDINARY REMINDER ROW, so the dispatcher, the
    | sweeper, the claim fencing, the two-attempt ceiling and
    | `messages.reminder_id` are untouched and know nothing about follow-ups.
    |
    | Every number here is CONFIGURATION. These values decide how often Sanad
    | speaks to a subscriber unprompted, which is a product decision and must
    | never be a literal buried in a class. They are deliberately NOT derived
    | from the recurrence limits: «كل يوم الساعة ٩» is something the subscriber
    | asked to receive, while a follow-up ask is Sanad returning to a question
    | on its own, and the two do not share a tolerance.
    |
    */

    // Master switch. Off ⇒ no follow-up is created and no ask is materialised.
    // One-time and recurring reminders are entirely unaffected either way.
    'enabled' => filter_var(env('FOLLOW_UPS_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    | How many LIVE follow-ups one subscriber may hold at once (open, awaiting an
    | answer, or operationally blocked). At the cap a new follow-up is REFUSED;
    | nothing existing is abandoned or deleted to make room, because a loop the
    | subscriber asked Sanad to watch is not the platform's to drop.
    */
    'max_open_per_subscriber' => (int) env('FOLLOW_UPS_MAX_OPEN', 5),

    /*
    | The ask budget: how many times Sanad may ask about ONE loop in total,
    | counting only asks that genuinely left the platform. This is the ONLY
    | stopping rule in V1 — there is no deadline and no `expired` state — so it
    | is what stands between "following up" and pestering.
    */
    'max_asks_per_follow_up' => (int) env('FOLLOW_UPS_MAX_ASKS', 3),

    /*
    | The minimum wall-clock gap between one ask leaving the platform and the
    | next becoming eligible. A floor, never a schedule: an answer ends the loop
    | before the gap elapses, and a blocked follow-up waits indefinitely.
    */
    'min_ask_interval_hours' => (int) env('FOLLOW_UPS_MIN_ASK_INTERVAL_HOURS', 24),

    /*
    | How long after an ask a subscriber's reply may still be read as an answer
    | TO THAT ASK. Beyond it, correlation is not strong enough: «تمام» eight days
    | later is a reply to whatever is being discussed now, not to a question
    | Sanad asked last week, and resolving on it would close a loop on evidence
    | that does not exist.
    */
    'answer_window_hours' => (int) env('FOLLOW_UPS_ANSWER_WINDOW_HOURS', 72),

    // How many follow-ups one materialisation run may consider.
    'materialise_batch' => (int) env('FOLLOW_UPS_MATERIALISE_BATCH', 100),

    /*
    | The default bound on the listing tool. The HARD ceiling is declared in code
    | (FollowUpService::LIST_MAX) because the tool's output schema promises it at
    | boot; configuration may only narrow it from there.
    */
    'list_limit' => (int) env('FOLLOW_UPS_LIST_LIMIT', 10),

    /*
    |--------------------------------------------------------------------------
    | The approved WhatsApp FOLLOW-UP template — its own external dependency
    |--------------------------------------------------------------------------
    |
    | A follow-up ask is a QUESTION («دفعت الفاتورة؟»), not a reminder, and Meta
    | approves templates per template. The generic reminder template is therefore
    | NOT semantically approved for it, and reusing it would put unapproved words
    | in front of a subscriber — so the follow-up template is a separate
    | configuration identity with its own launch dependency.
    |
    | `ready` stays false and `name` stays empty until a real template is
    | approved: NO TEMPLATE NAME IS EVER INVENTED IN CODE. Without one, an ask
    | that would fall outside the service window is not created at all and the
    | follow-up is held `blocked` — which spends no ask budget and produces no
    | stream of failed deliveries.
    |
    */
    'whatsapp' => [
        'template' => [
            'ready' => filter_var(env('WHATSAPP_FOLLOW_UP_TEMPLATE_READY', false), FILTER_VALIDATE_BOOL),
            'name' => env('WHATSAPP_FOLLOW_UP_TEMPLATE_NAME'),
            'language' => env('WHATSAPP_FOLLOW_UP_TEMPLATE_LANGUAGE', 'ar'),
        ],
    ],
];
