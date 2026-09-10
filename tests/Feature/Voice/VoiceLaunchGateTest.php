<?php

declare(strict_types=1);

use App\Enums\LaunchGateState;
use App\Services\Launch\Checks\VoiceChecks;
use App\Services\Launch\LaunchReadiness;
use App\Support\Launch\LaunchGateRegistry;

/**
 * The `voice.transcription` launch gate, now that there is something to report.
 *
 * The gate's job changed in this phase: it used to DECLARE an absence, and now
 * it reads configuration. What did not change is the rule it lives under —
 * a gate reports what is TRUE, and a gate that cannot verify something says so
 * rather than assuming either way (ADR-0045).
 */
it('is READY when the feature is on, media is reachable and a transcription model routes', function () {
    voiceConfigure();

    $outcome = VoiceChecks::transcription();
    $text = $outcome->summary.' '.implode(' ', array_map(static fn ($d): string => $d->value, $outcome->details));

    expect($outcome->state)->toBe(LaunchGateState::Ready)
        ->and($outcome->state->blocksLaunch())->toBeFalse()
        // It prints the model the catalog actually resolved — the honest
        // opposite of guessing one.
        ->and($text)->toContain('test-transcribe')
        // And never a credential.
        ->and($text)->not->toContain('test-groq-key')
        ->and($text)->not->toContain('TEST_ACCESS_TOKEN');
});

it('states the guarantee and the cost honestly on the readiness row', function () {
    voiceConfigure();

    $values = implode(' ', array_map(static fn ($d): string => $d->value, VoiceChecks::transcription()->details));

    // Two things an operator must not have to discover from the code: this is
    // not exactly-once, and these usage rows carry no price.
    expect($values)->toContain('exactly-once')
        ->and($values)->toContain('بلا سعر');
});

it('is NOT READY when WhatsApp media cannot be fetched', function () {
    voiceConfigure(['whatsapp.access_token' => null]);

    // The media fetch uses the same credential as sending, so an incomplete
    // WhatsApp integration cannot download a voice note either.
    expect(VoiceChecks::transcription()->state)->toBe(LaunchGateState::NotReady);
});

it('is NOT READY when the routed provider cannot actually transcribe', function () {
    voiceConfigure();

    // A chat-only provider, catalogued for transcription. Operator data must
    // never be able to crash a worker, so this is treated as NO ROUTE — by the
    // pipeline and by this gate alike, which is why they cannot disagree.
    voiceRegisterChatOnlyProvider('chatonly');

    config([
        'ai.providers.groq.transcription_model' => null,
        'ai.providers.openai.transcription_model' => null,
        'ai.catalog' => [[
            'provider' => 'chatonly',
            'model' => 'pretend-transcribe',
            'capabilities' => ['transcription'],
            'enabled' => true,
            'priority' => 100,
        ]],
    ]);

    $outcome = VoiceChecks::transcription();
    $values = implode(' ', array_map(static fn ($d): string => $d->value, $outcome->details));

    expect($outcome->state)->toBe(LaunchGateState::NotReady)
        ->and($outcome->state->blocksLaunch())->toBeTrue()
        ->and($values)->toContain('لا يوجد');
});

it('names the selected provider and counts the others that could serve instead', function () {
    voiceConfigure([
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.transcription_model' => 'openai-transcribe',
        'ai.providers.groq.transcription_model' => 'groq-transcribe',
    ]);

    $outcome = VoiceChecks::transcription();
    $values = implode(' ', array_map(static fn ($d): string => $d->value, $outcome->details));

    // Both providers are eligible, and the row says so — the capability is not
    // one vendor's, and an operator should be able to see that at a glance.
    expect($outcome->state)->toBe(LaunchGateState::Ready)
        ->and($values)->toContain('openai')
        ->and($values)->toContain('groq')
        ->and($values)->toContain('2 (')
        // Still never a credential.
        ->and($values)->not->toContain('test-openai-key')
        ->and($values)->not->toContain('test-groq-key');
});

it('is READY on either provider alone, and names whichever one routes', function (string $provider, string $model) {
    voiceConfigure([
        'ai.provider' => $provider,
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.transcription_model' => null,
        'ai.providers.groq.transcription_model' => null,
        "ai.providers.{$provider}.transcription_model" => $model,
    ]);

    $outcome = VoiceChecks::transcription();
    $values = implode(' ', array_map(static fn ($d): string => $d->value, $outcome->details));

    // READY means "a routable SupportsTranscription provider with a usable
    // credential exists" — never "Groq is configured".
    expect($outcome->state)->toBe(LaunchGateState::Ready)
        ->and($values)->toContain($provider)
        ->and($values)->toContain($model);
})->with([
    'openai' => ['openai', 'openai-transcribe'],
    'groq' => ['groq', 'groq-transcribe'],
]);

it('is NOT READY when the only transcription provider has no usable credential', function () {
    voiceConfigure([
        'ai.providers.groq.transcription_model' => 'groq-transcribe',
        'ai.providers.groq.api_key' => '',
        'ai.providers.openai.transcription_model' => null,
    ]);

    $outcome = VoiceChecks::transcription();

    // An unkeyed provider is not a route: the router already refuses it, so the
    // gate never reports a capability that would fail on the first voice note.
    expect($outcome->state)->toBe(LaunchGateState::NotReady)
        ->and($outcome->state->blocksLaunch())->toBeTrue();
});

it('is still a REQUIRED V1 gate, and blocks a launch while it is not ready', function () {
    voiceConfigure(['voice.enabled' => false]);

    $gate = collect(app(LaunchGateRegistry::class)->all())
        ->first(static fn ($g): bool => $g->key === 'voice.transcription');

    expect($gate)->not->toBeNull()
        ->and($gate->requiredForV1)->toBeTrue();

    $blockers = array_map(
        static fn ($status): string => $status->gate->key,
        app(LaunchReadiness::class)->blockers(),
    );

    expect($blockers)->toContain('voice.transcription');
});

it('no longer reports voice as NOT IMPLEMENTED anywhere', function () {
    voiceConfigure();

    foreach ([true, false] as $enabled) {
        config(['voice.enabled' => $enabled]);

        // A gate that declares absence after the thing exists is a lie the
        // readiness screen would repeat to whoever decides to launch.
        expect(VoiceChecks::transcription()->state)->not->toBe(LaunchGateState::NotImplemented);
    }
});
