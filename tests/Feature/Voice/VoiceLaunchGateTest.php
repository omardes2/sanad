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
    // The catalog claims a provider transcribes; the adapter says otherwise.
    // Operator data must never crash a worker, so this is treated as no route
    // — by the pipeline and by this gate alike.
    config([
        'ai.providers.groq.transcription_model' => null,
        'ai.providers.openai.transcription_model' => 'pretend-model',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.provider' => 'openai',
    ]);

    $outcome = VoiceChecks::transcription();

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
