<?php

declare(strict_types=1);

use App\Enums\LaunchGateState;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Launch\Checks\AiChecks;
use App\Services\Launch\Checks\FeatureChecks;
use App\Services\Launch\Checks\MemoryChecks;
use App\Services\Launch\Checks\PlatformChecks;
use App\Services\Launch\Checks\ReminderChecks;
use App\Services\Launch\Checks\VoiceChecks;
use App\Services\Launch\Checks\WhatsAppChecks;
use App\Services\Launch\LaunchReadiness;
use App\Services\Platform\InfrastructureHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Scheduler: silence is NOT a blocker
|--------------------------------------------------------------------------
*/

it('separates scheduler configuration, observed execution and last activity', function () {
    config()->set('reminders.enabled', true);

    $outcome = ReminderChecks::scheduler();
    $labels = array_map(static fn ($d): string => $d->label, $outcome->details);

    expect($labels)->toContain('إعداد المُجدوِل')
        ->and($labels)->toContain('تنفيذ مرصود')
        ->and($labels)->toContain('آخر نشاط مرصود');
});

it('reports NOT OBSERVED — never blocked — when the scheduler is configured but nothing was due', function () {
    config()->set('reminders.enabled', true);

    $outcome = ReminderChecks::scheduler();

    expect($outcome->state)->toBe(LaunchGateState::NotObserved)
        ->and($outcome->state->blocksLaunch())->toBeFalse();
});

it('becomes READY once a reminder has actually been claimed', function () {
    config()->set('reminders.enabled', true);

    Reminder::factory()->for(User::factory())->create([
        'status' => ReminderStatus::Sent,
        'claimed_at' => now()->subMinutes(5),
        'dispatched_at' => now()->subMinutes(5),
    ]);

    expect(ReminderChecks::scheduler()->state)->toBe(LaunchGateState::Ready);
});

it('blocks the scheduler gate only when configuration switches delivery off', function () {
    config()->set('reminders.enabled', false);

    $outcome = ReminderChecks::scheduler();

    expect($outcome->state)->toBe(LaunchGateState::NotReady)
        ->and($outcome->state->blocksLaunch())->toBeTrue();
});

it('never marks the cron gate NOT READY from an absence of evidence', function () {
    $outcome = PlatformChecks::schedulerCron();

    expect($outcome->state)->toBe(LaunchGateState::NotObserved)
        ->and($outcome->state->blocksLaunch())->toBeFalse();

    $values = array_map(static fn ($d): string => $d->value, $outcome->details);

    // It must say so in words, not only in the state.
    expect(implode(' ', $values))->toContain('غياب الأثر ليس دليلًا على تعطّل');
});

it('marks the cron gate READY when a scheduled effect is observed', function () {
    Reminder::factory()->for(User::factory())->create([
        'status' => ReminderStatus::Sent,
        'claimed_at' => now()->subHour(),
    ]);

    expect(PlatformChecks::schedulerCron()->state)->toBe(LaunchGateState::Ready);
});

/*
|--------------------------------------------------------------------------
| Structural blockers only
|--------------------------------------------------------------------------
*/

it('blocks delivery when it is switched off', function () {
    config()->set('reminders.enabled', false);

    expect(ReminderChecks::delivery()->state)->toBe(LaunchGateState::NotReady);
});

it('blocks delivery when no channel can send', function () {
    config()->set('reminders.enabled', true);
    config()->set('services.whatsapp.access_token', null);

    expect(ReminderChecks::delivery()->state)->toBe(LaunchGateState::NotReady);
});

it('marks the template gate BLOCKED EXTERNAL until a real template is configured', function () {
    config()->set('reminders.whatsapp.template.ready', false);
    config()->set('reminders.whatsapp.template.name', null);

    expect(ReminderChecks::template()->state)->toBe(LaunchGateState::BlockedExternal);

    config()->set('reminders.whatsapp.template.ready', true);
    config()->set('reminders.whatsapp.template.name', 'sanad_reminder_v1');
    config()->set('reminders.whatsapp.template.language', 'ar');

    expect(ReminderChecks::template()->state)->toBe(LaunchGateState::Ready);
});

it('does not mark the template ready on the flag alone', function () {
    config()->set('reminders.whatsapp.template.ready', true);
    config()->set('reminders.whatsapp.template.name', '');
    config()->set('reminders.whatsapp.template.language', 'ar');

    expect(ReminderChecks::template()->state)->toBe(LaunchGateState::BlockedExternal);
});

it('reports the queue unavailable as a blocker but an unknown worker as NOT OBSERVED', function () {
    app()->instance(InfrastructureHealth::class, new class extends InfrastructureHealth
    {
        public function redis(): bool
        {
            return false;
        }

        public function horizon(): string
        {
            return 'unavailable';
        }

        public function queueDepths(): array
        {
            return [];
        }
    });

    expect(PlatformChecks::queueWorker()->state)->toBe(LaunchGateState::NotReady);

    app()->instance(InfrastructureHealth::class, new class extends InfrastructureHealth
    {
        public function redis(): bool
        {
            return true;
        }

        public function horizon(): string
        {
            return 'unavailable';
        }

        public function queueDepths(): array
        {
            return [];
        }
    });

    expect(PlatformChecks::queueWorker()->state)->toBe(LaunchGateState::NotObserved);
});

/*
|--------------------------------------------------------------------------
| Unimplemented V1 features report the truth
|--------------------------------------------------------------------------
*/

/*
 | Voice transcription is IMPLEMENTED as of this phase, so its gate reports
 | configuration instead of declaring absence. What it must never do is invent
 | a provider: every name it prints has to come from the catalog that actually
 | resolved, which is why the disabled and unroutable cases print none at all.
 */
it('reports voice transcription as NOT READY when the feature is switched off', function () {
    config()->set('voice.enabled', false);

    $outcome = VoiceChecks::transcription();
    $text = $outcome->summary.' '.implode(' ', array_map(static fn ($d): string => $d->value, $outcome->details));

    expect($outcome->state)->toBe(LaunchGateState::NotReady)
        ->and($outcome->state->blocksLaunch())->toBeTrue()
        // Off does not mean the voice note vanishes — that is the whole point.
        ->and($text)->toContain('بصمت')
        ->and(strtolower($text))->not->toContain('groq')
        ->and(strtolower($text))->not->toContain('whisper');
});

it('reports voice transcription as NOT READY with no routable transcription model', function () {
    config()->set('voice.enabled', true);
    config()->set('ai.catalog', []);
    config()->set('ai.providers.groq.transcription_model', null);
    config()->set('ai.providers.openai.transcription_model', null);
    config()->set('whatsapp.enabled', true);
    config()->set('whatsapp.access_token', 'test-token');
    config()->set('whatsapp.phone_number_id', '123456');
    config()->set('ai.catalog_source', 'config');

    $outcome = VoiceChecks::transcription();

    expect($outcome->state)->toBe(LaunchGateState::NotReady)
        ->and($outcome->state->blocksLaunch())->toBeTrue();
});

it('reports the other unbuilt V1 features as NOT IMPLEMENTED', function (callable $check) {
    expect($check()->state)->toBe(LaunchGateState::NotImplemented);
})->with([
    'follow-up until done' => [fn () => FeatureChecks::followUpUntilDone()],
    'morning brief' => [fn () => FeatureChecks::morningBrief()],
    'rate limiting' => [fn () => AiChecks::rateLimiting()],
]);

/*
 | This is what keeps `FeatureChecks` honest from the other side. The gates above
 | DECLARE that these features do not exist; this test proves it, and fails the
 | moment an implementation lands while the gate still says `not_implemented`.
 |
 | `app/Services/Launch` is excluded because that directory is where the absence
 | is declared — its own method names necessarily mention the missing features.
 */
it('proves the codebase really has no implementation for the features it calls unbuilt', function (string $pattern) {
    $hits = shell_exec(
        'grep -rniE '.escapeshellarg($pattern).' '.escapeshellarg(app_path())
        .' | grep -v '.escapeshellarg('app/Services/Launch/').' || true'
    );

    expect(trim((string) $hits))->toBe('');
})->with([
    // `transcription` and `recurrence` were here until they were built. Removing a
    // pattern from this list is the deliberate, visible act of saying "this now
    // exists" — and it is only legitimate alongside a gate that reports rather
    // than declares.
    'follow-up' => ['class .*FollowUp|function followUp'],
    'morning brief' => ['class .*MorningBrief|class .*DailyBrief'],
]);

/*
|--------------------------------------------------------------------------
| Memory
|--------------------------------------------------------------------------
*/

it('marks memory encryption NOT READY when either key is missing', function () {
    config()->set('memory.fingerprint_key', null);

    expect(MemoryChecks::encryption()->state)->toBe(LaunchGateState::NotReady);
});

it('marks memory encryption READY with both keys, and never prints a key', function () {
    $outcome = MemoryChecks::encryption();
    $values = implode(' ', array_map(static fn ($d): string => $d->value, $outcome->details));

    expect($outcome->state)->toBe(LaunchGateState::Ready)
        ->and($values)->not->toContain((string) config('memory.key'))
        ->and($values)->not->toContain((string) config('memory.fingerprint_key'))
        ->and($values)->not->toContain('base64:');
});

it('states the rotation commitment rather than implying full support', function () {
    $values = implode(' ', array_map(static fn ($d): string => $d->value, MemoryChecks::encryption()->details));

    expect($values)->toContain('لا توجد مرحلة إعادة تشفير');
});

/*
|--------------------------------------------------------------------------
| Provider and WhatsApp
|--------------------------------------------------------------------------
*/

it('marks the AI gate NOT READY when AI is disabled', function () {
    config()->set('ai.enabled', false);

    expect(AiChecks::provider()->state)->toBe(LaunchGateState::NotReady);
});

it('never prints a credential or a fingerprint on the AI gate', function () {
    $outcome = AiChecks::provider();
    $values = implode(' ', array_map(static fn ($d): string => $d->value, $outcome->details));

    expect($values)->not->toContain('sk-')
        ->and(strtolower($values))->not->toContain('fingerprint')
        ->and(strtolower($values))->not->toContain('last4');
});

it('reports WhatsApp with presence booleans only', function () {
    config()->set('services.whatsapp.access_token', 'super-secret-token');

    $values = implode(' ', array_map(static fn ($d): string => $d->value, WhatsAppChecks::messaging()->details));

    expect($values)->not->toContain('super-secret-token');
});

it('explains why each non-exposed tool is hidden', function () {
    $labels = array_map(static fn ($d): string => $d->label, AiChecks::toolCalling()->details);

    expect(implode(' ', $labels))->toContain('غير معروضة');
});

/*
|--------------------------------------------------------------------------
| CyberSource
|--------------------------------------------------------------------------
*/

it('keeps CyberSource BLOCKED_EXTERNAL and guesses nothing about the integration', function () {
    $status = collect(app(LaunchReadiness::class)->evaluate())
        ->firstWhere(fn ($s) => $s->gate->key === 'payments.cybersource');

    $values = implode(' ', array_map(static fn ($d): string => $d->value, $status->details));

    expect($status->state)->toBe(LaunchGateState::BlockedExternal)
        ->and($status->blocksLaunch())->toBeTrue()
        ->and($values)->toContain('BLOCKED_EXTERNAL: BANK_CYBERSOURCE_DETAILS')
        ->and($values)->toContain('إعادة توجيه المتصفّح ليست إثباتًا للدفع')
        ->and($values)->toContain('لا تدخل بيانات البطاقات');
});
