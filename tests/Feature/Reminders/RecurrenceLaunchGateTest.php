<?php

declare(strict_types=1);

use App\Enums\LaunchGateState;
use App\Services\Launch\Checks\ReminderChecks;
use App\Services\Launch\LaunchReadiness;
use App\Support\Launch\LaunchGateRegistry;

/**
 * The `reminders.recurring` launch gate, now that recurrence exists.
 *
 * The gate's job changed in this phase: it used to DECLARE an absence and now it
 * READS configuration. What did not change is the rule it lives under — a gate
 * reports what is true, a gate that cannot verify something says so, and no
 * number invented here becomes launch authority (ADR-0045, ADR-0046).
 *
 * The subtle assertion in this file is the LAST one: recurrence being complete
 * on Sanad's side while the WhatsApp template is still missing must read as
 * exactly that — recurrence ready, the TEMPLATE gate blocking — because
 * collapsing the two would make it impossible to see which side is unfinished.
 */
function recGateConfigure(array $overrides = []): void
{
    config(array_merge([
        'reminders.enabled' => true,
        'reminders.recurrence.enabled' => true,
        'reminders.recurrence.horizon_days' => 30,
        'reminders.recurrence.max_occurrences_per_schedule' => 35,
        'reminders.recurrence.max_active_schedules_per_subscriber' => 10,
        'reminders.whatsapp.template.ready' => false,
        'reminders.whatsapp.template.name' => '',
        'reminders.whatsapp.template.language' => 'ar',
        'whatsapp.enabled' => true,
        'whatsapp.access_token' => 'TEST_ACCESS_TOKEN',
        'whatsapp.phone_number_id' => 'PNID_123',
    ], $overrides));
}

/** Every detail value of the gate, as one searchable string. */
function recGateText(): string
{
    $outcome = ReminderChecks::recurrence();

    return $outcome->summary.' '.implode(' ', array_map(
        static fn ($d): string => $d->label.': '.$d->value,
        $outcome->details,
    ));
}

it('is READY when recurrence and delivery are both on', function () {
    recGateConfigure();

    $outcome = ReminderChecks::recurrence();

    expect($outcome->state)->toBe(LaunchGateState::Ready)
        ->and($outcome->state->blocksLaunch())->toBeFalse();
});

it('reports the bounds it actually enforces, read from configuration', function () {
    recGateConfigure([
        'reminders.recurrence.horizon_days' => 14,
        'reminders.recurrence.max_occurrences_per_schedule' => 20,
        'reminders.recurrence.max_active_schedules_per_subscriber' => 3,
    ]);

    $text = recGateText();

    // The row shows the bounds this environment is running under, not the
    // numbers that were approved once and hard-coded into prose.
    expect($text)->toContain('14')
        ->and($text)->toContain('20')
        ->and($text)->toContain('3');
});

it('states the occurrence identity and the timing rule on the row', function () {
    recGateConfigure();

    $text = recGateText();

    // Two facts an operator must not have to read the code for: an occurrence
    // is its own reminder row with its own attempt budget, and a spring-forward
    // gap is shifted by its real size rather than a presumed hour.
    expect($text)->toContain('هوية المرّة')
        ->and($text)->toContain('لا يُعاد استخدام صفّ لعدّة تسليمات')
        ->and($text)->toContain('الفجوة الربيعية تُزاح بمقدارها الفعلي')
        // And that V1 has no in-place edit: a change is a cancel plus a create.
        ->and($text)->toContain('لا تعديل في V1');
});

it('invents no lag threshold and no rate as launch authority', function () {
    recGateConfigure();

    $text = recGateText();

    // A materialisation lag is a rate over a window; no threshold has been
    // approved as launch authority, so none appears here (ADR-0046).
    expect($text)->not->toContain('تأخّر التوليد')
        ->and($text)->not->toContain('نسبة')
        ->and($text)->not->toContain('%');
});

it('is NOT READY when recurrence is switched off, and says one-time reminders are unaffected', function () {
    recGateConfigure(['reminders.recurrence.enabled' => false]);

    $outcome = ReminderChecks::recurrence();

    expect($outcome->state)->toBe(LaunchGateState::NotReady)
        ->and($outcome->state->blocksLaunch())->toBeTrue()
        ->and(recGateText())->toContain('التذكيرات المفردة غير متأثّرة');
});

it('is NOT READY when recurrence is on but delivery is off', function () {
    recGateConfigure(['reminders.enabled' => false]);

    $outcome = ReminderChecks::recurrence();

    // Occurrences nothing can deliver is the one combination worth refusing
    // outright: it would manufacture rows and silence.
    expect($outcome->state)->toBe(LaunchGateState::NotReady)
        ->and(recGateText())->toContain('ستُولَّد مرّات لا يُسلِّمها شيء');
});

it('names the missing WhatsApp template on the row WITHOUT counting it as recurrence\'s blocker', function () {
    recGateConfigure();

    $outcome = ReminderChecks::recurrence();
    $text = recGateText();

    // Recurrence is complete on Sanad's side…
    expect($outcome->state)->toBe(LaunchGateState::Ready)
        // …and the external dependency is stated plainly, because a recurring
        // reminder fires long after the subscriber's last message and therefore
        // almost always outside the free-form window.
        ->and($text)->toContain('template_required')
        ->and($text)->toContain('حاجز البند الخارجي لا حاجز التكرار');

    // And the blocker is the TEMPLATE gate, which is where it belongs.
    $blockers = array_map(
        static fn ($status): string => $status->gate->key,
        app(LaunchReadiness::class)->blockers(),
    );

    expect($blockers)->toContain('reminders.template')
        ->and($blockers)->not->toContain('reminders.recurring');
});

it('reads differently once a template is actually approved and configured', function () {
    recGateConfigure([
        'reminders.whatsapp.template.ready' => true,
        'reminders.whatsapp.template.name' => 'sanad_reminder_v1',
        'reminders.whatsapp.template.language' => 'ar',
    ]);

    $outcome = ReminderChecks::recurrence();

    expect($outcome->state)->toBe(LaunchGateState::Ready)
        ->and(recGateText())->toContain('معتمَد ومضبوط')
        ->and(recGateText())->not->toContain('template_required');
});

it('is the gate the registry resolves for reminders.recurring, and it is required for V1', function () {
    $gate = app(LaunchGateRegistry::class)->find('reminders.recurring');

    recGateConfigure(['reminders.recurrence.enabled' => false]);

    // The check provider is declared in the registry's own constant and is not
    // readable from outside (ADR-0046), so what is proven here is BEHAVIOURAL:
    // the gate evaluates to exactly what this check says, under a configuration
    // only this check responds to.
    expect($gate->requiredForV1)->toBeTrue()
        ->and($gate->evaluate()->state)->toBe(ReminderChecks::recurrence()->state)
        ->and($gate->evaluate()->summary)->toBe(ReminderChecks::recurrence()->summary)
        ->and($gate->evaluate()->state)->toBe(LaunchGateState::NotReady);

    recGateConfigure();

    expect($gate->evaluate()->state)->toBe(LaunchGateState::Ready)
        ->and($gate->evaluate()->summary)->toBe(ReminderChecks::recurrence()->summary);
});
