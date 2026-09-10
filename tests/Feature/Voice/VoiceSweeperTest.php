<?php

declare(strict_types=1);

use App\Enums\TranscriptionFailureReason;
use App\Enums\TranscriptionStatus;
use App\Jobs\TranscribeVoiceNote;
use App\Services\Voice\VoiceTranscriptionSweeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * The sweeper only RE-QUEUES.
 *
 * That is the point of these tests: every rule about ownership, budget and
 * settlement lives in the claim, under a row lock. If the sweeper decided any
 * of that too there would be two opinions about who owns a voice note, and two
 * opinions about ownership is how audio gets paid for twice.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    voiceConfigure();
    Queue::fake();
});

it('re-queues a voice note whose worker died holding the claim', function () {
    [$user, $account] = voiceSubscriber();
    voiceNote($user, $account, [
        'transcription_status' => TranscriptionStatus::Processing,
        'transcription_claim_token' => str_repeat('a', 32),
        'transcription_claimed_at' => now()->subSeconds(3600),
    ]);

    expect(app(VoiceTranscriptionSweeper::class)->sweep())->toBe(['recovered' => 1]);

    Queue::assertPushed(TranscribeVoiceNote::class, 1);
});

it('leaves a claim that is still within its lease alone', function () {
    [$user, $account] = voiceSubscriber();
    voiceNote($user, $account, [
        'transcription_status' => TranscriptionStatus::Processing,
        'transcription_claim_token' => str_repeat('a', 32),
        'transcription_claimed_at' => now(),
    ]);

    expect(app(VoiceTranscriptionSweeper::class)->sweep())->toBe(['recovered' => 0]);

    Queue::assertNothingPushed();
});

it('re-queues a voice note left pending long past its lease', function () {
    [$user, $account] = voiceSubscriber();
    voiceNote($user, $account, [
        'transcription_status' => TranscriptionStatus::Pending,
        'created_at' => now()->subSeconds(3600),
    ]);

    // A job released or lost before it ever claimed. Nothing else would notice.
    expect(app(VoiceTranscriptionSweeper::class)->sweep())->toBe(['recovered' => 1]);
});

it('never touches a settled voice note', function (TranscriptionStatus $status) {
    [$user, $account] = voiceSubscriber();
    voiceNote($user, $account, [
        'transcription_status' => $status,
        'transcription_failure_reason' => TranscriptionFailureReason::MediaExpired,
        'transcribed_at' => now(),
        'text_content' => 'نصّ',
        'created_at' => now()->subDay(),
    ]);

    expect(app(VoiceTranscriptionSweeper::class)->sweep())->toBe(['recovered' => 0]);

    Queue::assertNothingPushed();
})->with([
    'transcribed' => [TranscriptionStatus::Transcribed],
    'failed' => [TranscriptionStatus::Failed],
]);

it('ignores messages that are not voice notes at all', function () {
    [$user, $account] = voiceSubscriber();
    voiceNote($user, $account, [
        'voice_media_id' => null,
        'transcription_status' => TranscriptionStatus::Pending,
        'created_at' => now()->subDay(),
    ]);

    expect(app(VoiceTranscriptionSweeper::class)->sweep())->toBe(['recovered' => 0]);
});

it('respects the configured batch size', function () {
    [$user, $account] = voiceSubscriber();
    config(['voice.batch' => 2]);

    foreach (range(1, 5) as $i) {
        voiceNote($user, $account, [
            'transcription_status' => TranscriptionStatus::Pending,
            'created_at' => now()->subSeconds(3600),
        ]);
    }

    expect(app(VoiceTranscriptionSweeper::class)->sweep())->toBe(['recovered' => 2]);

    Queue::assertPushed(TranscribeVoiceNote::class, 2);
});
