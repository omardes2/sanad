<?php

declare(strict_types=1);

use App\Enums\LaunchGateState;
use App\Services\Launch\Checks\FeatureChecks;
use App\Services\Launch\Checks\FollowUpChecks;
use App\Services\Launch\LaunchReadiness;
use App\Support\Launch\LaunchGateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The two gates, and the split between them.
 *
 * `reminders.follow_up` reports Sanad's side. `follow_up.template` carries the
 * external approval. A follow-up ask is a QUESTION and Meta approves templates one
 * at a time, so the reminder template being live says nothing about whether Sanad
 * may ask «دفعت الفاتورة؟» — and one green light covering both would be a lie
 * whichever side was missing.
 */
uses(RefreshDatabase::class);

function fuGateText(): string
{
    $outcome = FollowUpChecks::followUp();

    return $outcome->summary.' '.implode(' ', array_map(
        static fn ($d): string => $d->label.': '.$d->value,
        $outcome->details,
    ));
}

it('is READY when follow-up and reminder delivery are both on', function () {
    fuConfigure();

    $outcome = FollowUpChecks::followUp();

    expect($outcome->state)->toBe(LaunchGateState::Ready)
        ->and($outcome->state->blocksLaunch())->toBeFalse();
});

it('reports the bounds and the authority rules it actually enforces', function () {
    fuConfigure([
        'follow_ups.max_open_per_subscriber' => 7,
        'follow_ups.max_asks_per_follow_up' => 2,
        'follow_ups.min_ask_interval_hours' => 12,
        'follow_ups.answer_window_hours' => 48,
    ]);

    $text = fuGateText();

    expect($text)->toContain('7')
        ->and($text)->toContain('2')
        ->and($text)->toContain('12')
        ->and($text)->toContain('48')
        // The two authorities, and the rule that makes Sanad ask rather than assume.
        ->and($text)->toContain('طلب صريح من المشترك')
        ->and($text)->toContain('وبلا وقت يسأل سَنَد ولا يفترض')
        // One outstanding ask, and a budget measured in DELIVERY truth.
        ->and($text)->toContain('سؤال واحد معلَّق')
        ->and($text)->toContain('status = sent')
        ->and($text)->toContain('العامل الذي مات قبل الشبكة لا يستهلك')
        // `attempts` is never offered as the budget rule, because it is not one.
        ->and($text)->not->toContain('attempts > 0')
        // Silence closes nothing.
        ->and($text)->toContain('الصمت لا يُغلق شيئًا');
});

it('invents no rate and no threshold as launch authority', function () {
    fuConfigure();

    $text = fuGateText();

    expect($text)->not->toContain('%')
        ->and($text)->not->toContain('نسبة')
        ->and($text)->not->toContain('معدّل');
});

it('is NOT READY when follow-up is switched off, and says reminders are unaffected', function () {
    fuConfigure(['follow_ups.enabled' => false]);

    expect(FollowUpChecks::followUp()->state)->toBe(LaunchGateState::NotReady)
        ->and(FollowUpChecks::followUp()->state->blocksLaunch())->toBeTrue()
        ->and(fuGateText())->toContain('التذكيرات غير متأثّرة');
});

it('is NOT READY when follow-up is on but reminder delivery is off', function () {
    fuConfigure(['reminders.enabled' => false]);

    expect(FollowUpChecks::followUp()->state)->toBe(LaunchGateState::NotReady)
        ->and(fuGateText())->toContain('ستُولَّد أسئلة لا يُسلِّمها شيء');
});

it('names the missing follow-up template on the row WITHOUT counting it as this gate\'s blocker', function () {
    fuConfigure([
        'follow_ups.whatsapp.template.ready' => false,
        'follow_ups.whatsapp.template.name' => '',
    ]);

    $outcome = FollowUpChecks::followUp();

    // Sanad's side is complete…
    expect($outcome->state)->toBe(LaunchGateState::Ready)
        // …and the hold is described precisely: held, not failed, and not spending
        // the subscriber's ask budget.
        ->and(fuGateText())->toContain('موقوفة تشغيليًا')
        ->and(fuGateText())->toContain('بلا استهلاك ميزانية')
        ->and(fuGateText())->toContain('حاجز البند الخارجي لا حاجز المتابعة');

    // And the blocker is the TEMPLATE gate, which is where it belongs.
    $blockers = array_map(
        static fn ($status): string => $status->gate->key,
        app(LaunchReadiness::class)->blockers(),
    );

    expect($blockers)->toContain('follow_up.template')
        ->and($blockers)->not->toContain('reminders.follow_up');
});

it('keeps the follow-up template a SEPARATE dependency from the reminder template', function () {
    // The reminder template is approved and configured; the follow-up one is not.
    fuConfigure([
        'reminders.whatsapp.template.ready' => true,
        'reminders.whatsapp.template.name' => 'sanad_reminder_v1',
        'reminders.whatsapp.template.language' => 'ar',
        'follow_ups.whatsapp.template.ready' => false,
        'follow_ups.whatsapp.template.name' => '',
    ]);

    $template = FollowUpChecks::template();

    // A live reminder template does NOT make the follow-up one ready: Meta
    // approves templates one at a time, and a question is not a reminder.
    expect($template->state)->toBe(LaunchGateState::BlockedExternal)
        ->and($template->state->blocksLaunch())->toBeTrue();

    $text = $template->summary.' '.implode(' ', array_map(static fn ($d): string => $d->value, $template->details));

    expect($text)->toContain('ليس تذكيرًا')
        ->and($text)->toContain('sanad:follow-ups:unblock')
        // No template name is invented, and the reminder's is not borrowed.
        ->and($text)->not->toContain('sanad_reminder_v1');
});

it('reads the follow-up template as ready only when it is genuinely configured', function () {
    fuConfigure();

    expect(FollowUpChecks::template()->state)->toBe(LaunchGateState::Ready);

    foreach ([
        ['follow_ups.whatsapp.template.ready' => false],
        ['follow_ups.whatsapp.template.name' => ''],
        ['follow_ups.whatsapp.template.language' => ''],
    ] as $gap) {
        fuConfigure($gap);

        expect(FollowUpChecks::template()->state)->toBe(LaunchGateState::BlockedExternal, json_encode($gap));
    }
});

it('is the gate the registry resolves for reminders.follow_up, and both are required for V1', function () {
    $registry = app(LaunchGateRegistry::class);
    $followUp = $registry->find('reminders.follow_up');
    $template = $registry->find('follow_up.template');

    fuConfigure(['follow_ups.enabled' => false]);

    // Behavioural proof: the check provider is declared in the registry's own
    // constant and is not readable from outside (ADR-0046).
    expect($followUp->requiredForV1)->toBeTrue()
        ->and($template->requiredForV1)->toBeTrue()
        ->and($followUp->evaluate()->state)->toBe(LaunchGateState::NotReady)
        ->and($followUp->evaluate()->summary)->toBe(FollowUpChecks::followUp()->summary);

    fuConfigure();

    expect($followUp->evaluate()->state)->toBe(LaunchGateState::Ready)
        ->and($template->evaluate()->summary)->toBe(FollowUpChecks::template()->summary);
});

it('no longer declares follow-up unimplemented anywhere', function () {
    // `FeatureChecks` is where an absence is DECLARED; the declaration is gone,
    // and the readiness guard's grep pattern for follow-up went with it.
    expect(method_exists(FeatureChecks::class, 'followUpUntilDone'))->toBeFalse();

    $guard = file_get_contents(base_path('tests/Feature/Launch/ReadinessStateTest.php'));

    expect($guard)->not->toContain('followUpUntilDone')
        ->and($guard)->not->toContain('class .*FollowUp');
});
