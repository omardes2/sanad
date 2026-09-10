<?php

declare(strict_types=1);

use App\Data\Voice\TranscriptionOutcome;
use App\Enums\CostSource;
use App\Enums\TranscriptionFailureReason;
use App\Enums\TranscriptionStatus;
use App\Enums\UsageDimension;
use App\Enums\UsageEventOutcome;
use App\Exceptions\WhatsApp\MediaFetchException;
use App\Jobs\ProcessInboundMessage;
use App\Jobs\TranscribeVoiceNote;
use App\Models\Message;
use App\Models\UsageEvent;
use App\Services\Voice\VoiceNoteTranscriber;
use App\Services\Voice\VoiceRefusalReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * The transcription pipeline: what it produces, what it refuses, and — the part
 * that actually costs money — WHEN it is allowed to call the provider.
 *
 * Almost every assertion in this file is really about one number: how many
 * requests reached `/audio/transcriptions`. A refusal that happens on the cheap
 * side of that boundary must show ZERO, and the retry budget must never show
 * more than two, whatever else happened.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    voiceConfigure();
    Queue::fake();
});

/** How many PAID requests actually left this process. */
function voiceProviderRequests(): int
{
    return collect(Http::recorded())
        ->filter(static fn (array $pair): bool => str_contains((string) $pair[0]->url(), '/audio/transcriptions'))
        ->count();
}

/** How many times the media BINARY was fetched (the metadata call is separate). */
function voiceDownloads(): int
{
    return collect(Http::recorded())
        ->filter(static fn (array $pair): bool => str_contains((string) $pair[0]->url(), 'lookaside.example'))
        ->count();
}

function voiceTranscribe(Message $message): TranscriptionOutcome
{
    return app(VoiceNoteTranscriber::class)->transcribe($message->getKey());
}

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('writes the transcript into the message own text and hands it to the reply path', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp(['text' => 'ذكّرني بالاجتماع غدًا']);
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);
    $message->refresh();

    expect($outcome->succeeded())->toBeTrue()
        ->and($message->transcription_status)->toBe(TranscriptionStatus::Transcribed)
        // The transcript IS the message's text. There is no second row and no
        // second column holding a rival copy of the same sentence.
        ->and($message->text_content)->toBe('ذكّرني بالاجتماع غدًا')
        ->and($message->transcribed_at)->not->toBeNull()
        ->and($message->transcription_provider)->toBe('groq')
        ->and($message->transcription_model)->toBe('test-transcribe')
        ->and($message->transcription_failure_reason)->toBeNull()
        // Measured from the bytes before the request, not reported after it.
        ->and($message->voice_duration_ms)->toBe(7500)
        ->and($message->voice_bytes)->toBe(strlen(voiceFixtureBytes()))
        // The claim is handed back: nothing owns a settled voice note.
        ->and($message->transcription_claim_token)->toBeNull()
        ->and(voiceProviderRequests())->toBe(1);

    // Only one message exists — the transcript did not become a second one.
    expect(Message::count())->toBe(1);
});

it('the job sends a transcribed voice note to the ordinary reply path', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    app()->call([new TranscribeVoiceNote($message->getKey()), 'handle']);

    Queue::assertPushed(ProcessInboundMessage::class, 1);
    expect(Message::count())->toBe(1);   // no refusal reply was staged
});

/*
|--------------------------------------------------------------------------
| Refusals BEFORE any paid work
|--------------------------------------------------------------------------
| Each of these must reach the provider zero times. `precedesPaidWork()` is the
| same claim stated in code, and the last test in this block proves the two
| agree rather than trusting that they do.
*/

it('refuses a plan without voice before touching anything external', function () {
    [$user, $account] = voiceSubscriber(withVoice: false);
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);
    $message->refresh();

    expect($outcome->reason)->toBe(TranscriptionFailureReason::VoiceNotInPlan)
        ->and($outcome->providerRequested)->toBeFalse()
        ->and($message->transcription_status)->toBe(TranscriptionStatus::Failed)
        ->and($message->transcription_attempts)->toBe(0)
        ->and(voiceProviderRequests())->toBe(0)
        // Not even the media metadata: an entitlement refusal is free.
        ->and(collect(Http::recorded()))->toBeEmpty();
});

it('refuses an attached audio file that is not a voice note', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $message = voiceNote($user, $account, ['metadata' => ['provider' => 'whatsapp', 'voice' => false]]);

    $outcome = voiceTranscribe($message);

    expect($outcome->reason)->toBe(TranscriptionFailureReason::UnsupportedAudio)
        ->and(voiceProviderRequests())->toBe(0)
        ->and(collect(Http::recorded()))->toBeEmpty();
});

it('refuses a MIME type outside the allowlist using the type the channel declared', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $message = voiceNote($user, $account, ['voice_mime_type' => 'audio/mpeg']);

    $outcome = voiceTranscribe($message);

    expect($outcome->reason)->toBe(TranscriptionFailureReason::UnsupportedAudio)
        ->and(collect(Http::recorded()))->toBeEmpty();
});

it('refuses when no transcription model is catalogued, and never guesses one', function () {
    [$user, $account] = voiceSubscriber();
    config(['ai.providers.groq.transcription_model' => null, 'ai.providers.openai.transcription_model' => null]);
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);
    $message->refresh();

    expect($outcome->reason)->toBe(TranscriptionFailureReason::TranscriptionNotConfigured)
        ->and($message->transcription_model)->toBeNull()
        ->and(voiceProviderRequests())->toBe(0)
        // Discovered BEFORE the download: the bandwidth is saved and the
        // failure stays honest — "not configured", not "download failed".
        ->and(collect(Http::recorded()))->toBeEmpty();
});

it('refuses with a bounded reason when transcription is switched off', function () {
    [$user, $account] = voiceSubscriber();
    config(['voice.enabled' => false]);
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);
    $message->refresh();

    // Switched off does NOT mean silently dropped. That is the whole point.
    expect($outcome->reason)->toBe(TranscriptionFailureReason::TranscriptionNotConfigured)
        ->and($message->transcription_status)->toBe(TranscriptionStatus::Failed)
        ->and(collect(Http::recorded()))->toBeEmpty();
});

it('refuses a file the provider declares as oversized without downloading one byte', function () {
    [$user, $account] = voiceSubscriber();
    config(['voice.max_bytes' => 1024]);
    voiceFakeHttp(['file_size' => 9_000_000]);
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);

    expect($outcome->reason)->toBe(TranscriptionFailureReason::AudioTooLarge)
        ->and(voiceDownloads())->toBe(0)
        ->and(voiceProviderRequests())->toBe(0);
});

it('aborts the download when the bytes exceed the ceiling the metadata understated', function () {
    [$user, $account] = voiceSubscriber();
    config(['voice.max_bytes' => 100]);
    // The provider CLAIMS a small file and delivers a larger one. Metadata is a
    // claim; the bytes are the fact, and both are checked.
    voiceFakeHttp(['file_size' => 50]);
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);

    expect($outcome->reason)->toBe(TranscriptionFailureReason::AudioTooLarge)
        ->and(voiceProviderRequests())->toBe(0);
});

it('refuses audio longer than the ceiling, measured before the paid request', function () {
    [$user, $account] = voiceSubscriber();
    config(['voice.max_duration_seconds' => 5]);   // the fixture is 7.5s
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);
    $message->refresh();

    expect($outcome->reason)->toBe(TranscriptionFailureReason::AudioTooLong)
        ->and($message->voice_duration_ms)->toBe(7500)
        ->and(voiceProviderRequests())->toBe(0);
});

it('refuses audio whose duration the container does not state', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp(['audio' => 'this is not an ogg stream at all']);
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);

    // Guessing a duration would let exactly the file the ceiling exists for
    // through the ceiling.
    expect($outcome->reason)->toBe(TranscriptionFailureReason::UnsupportedAudio)
        ->and(voiceProviderRequests())->toBe(0);
});

it('refuses expired media permanently rather than retrying what cannot come back', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp(['metadata' => Http::response(['error' => ['message' => 'not found']], 404)]);
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);
    $message->refresh();

    expect($outcome->reason)->toBe(TranscriptionFailureReason::MediaExpired)
        ->and($message->transcription_status)->toBe(TranscriptionStatus::Failed)
        ->and(voiceProviderRequests())->toBe(0);
});

it('agrees with precedesPaidWork about which refusals are free', function (TranscriptionFailureReason $reason, callable $arrange) {
    [$user, $account] = voiceSubscriber(withVoice: $reason !== TranscriptionFailureReason::VoiceNotInPlan);
    $arrange();
    $message = voiceNote($user, $account, $reason === TranscriptionFailureReason::UnsupportedAudio
        ? ['voice_mime_type' => 'audio/mpeg']
        : []);

    $outcome = voiceTranscribe($message);

    expect($outcome->reason)->toBe($reason)
        ->and($reason->precedesPaidWork())->toBeTrue()
        ->and($outcome->providerRequested)->toBeFalse()
        ->and(voiceProviderRequests())->toBe(0);
})->with([
    'plan' => [TranscriptionFailureReason::VoiceNotInPlan, fn () => voiceFakeHttp()],
    'mime' => [TranscriptionFailureReason::UnsupportedAudio, fn () => voiceFakeHttp()],
    'too large' => [TranscriptionFailureReason::AudioTooLarge, function () {
        config(['voice.max_bytes' => 1024]);
        voiceFakeHttp(['file_size' => 9_000_000]);
    }],
    'too long' => [TranscriptionFailureReason::AudioTooLong, function () {
        config(['voice.max_duration_seconds' => 5]);
        voiceFakeHttp();
    }],
    'expired' => [TranscriptionFailureReason::MediaExpired, fn () => voiceFakeHttp([
        'metadata' => Http::response([], 404),
    ])],
    'not configured' => [TranscriptionFailureReason::TranscriptionNotConfigured, function () {
        config(['ai.providers.groq.transcription_model' => null]);
        voiceFakeHttp();
    }],
]);

/*
|--------------------------------------------------------------------------
| The paid boundary: attempts, unknown outcomes, and replay
|--------------------------------------------------------------------------
*/

it('records a positive provider refusal without a ledger row and without a retry', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp(['transcription' => Http::response(['error' => 'bad request'], 400)]);
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);
    $message->refresh();

    expect($outcome->reason)->toBe(TranscriptionFailureReason::TranscriptionFailed)
        ->and($outcome->providerRequested)->toBeTrue()
        ->and($message->transcription_status)->toBe(TranscriptionStatus::Failed)
        ->and($message->transcription_attempts)->toBe(1)
        ->and(voiceProviderRequests())->toBe(1)
        // A 4xx processed nothing. That absence is a fact here, not a guess.
        ->and(UsageEvent::count())->toBe(0);
});

it('records an empty transcript as consumption, because the provider did the work', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp(['transcription' => Http::response(['text' => '   '], 200)]);
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);
    $message->refresh();

    expect($outcome->reason)->toBe(TranscriptionFailureReason::TranscriptEmpty)
        ->and($message->transcription_status)->toBe(TranscriptionStatus::Failed)
        ->and($message->text_content)->toBeNull()
        // It listened; retrying would pay twice for the same silence.
        ->and(UsageEvent::where('type', UsageDimension::VoiceMessage->value)->count())->toBe(1)
        ->and(UsageEvent::first()->outcome)->toBe(UsageEventOutcome::DownstreamFailed);
});

it('treats an unproven outcome as unknown: one retry left, no ledger row, never a failure', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp(['transcription' => Http::response(['error' => 'upstream'], 500)]);
    $message = voiceNote($user, $account);

    $outcome = voiceTranscribe($message);
    $message->refresh();

    expect($outcome->result)->toBe(TranscriptionOutcome::UNKNOWN)
        ->and($outcome->providerRequested)->toBeTrue()
        // NOT failed: the provider may have processed and charged for it.
        ->and($message->transcription_status)->toBe(TranscriptionStatus::Pending)
        ->and($message->transcription_failure_reason)->toBe(TranscriptionFailureReason::TranscriptionUnknown)
        ->and($message->transcription_attempts)->toBe(1)
        // No row is invented, and its absence means UNKNOWN, never zero cost.
        ->and(UsageEvent::count())->toBe(0);
});

it('allows exactly one more physical request after an unknown outcome, and never a third', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp(['transcription' => Http::response(['error' => 'upstream'], 500)]);
    $message = voiceNote($user, $account);

    voiceTranscribe($message);                       // attempt 1 — unknown
    $second = voiceTranscribe($message->refresh());  // attempt 2 — unknown
    $third = voiceTranscribe($message->refresh());   // refused by the budget

    $message->refresh();

    expect($second->result)->toBe(TranscriptionOutcome::FAILED)
        ->and($second->reason)->toBe(TranscriptionFailureReason::TranscriptionUnknown)
        ->and($message->transcription_status)->toBe(TranscriptionStatus::Failed)
        // The row is closed; the truth about the provider is NOT asserted.
        ->and($message->transcription_failure_reason)->toBe(TranscriptionFailureReason::TranscriptionUnknown)
        ->and($message->transcription_attempts)->toBe(2)
        ->and($message->transcription_attempts)->toBeLessThanOrEqual((int) config('voice.max_attempts'))
        ->and(voiceProviderRequests())->toBe(2)
        ->and($third->result)->not->toBe(TranscriptionOutcome::UNKNOWN);
});

it('never calls the provider again once a transcript is persisted', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp(['text' => 'النص الأول']);
    $message = voiceNote($user, $account);

    voiceTranscribe($message);
    $again = voiceTranscribe($message->refresh());
    $message->refresh();

    expect($again->result)->toBe(TranscriptionOutcome::SKIPPED)
        ->and($again->note)->toBe('already_transcribed')
        // The persisted transcript is what prevents the replay — not a queue
        // lock, which expires, and not luck.
        ->and(voiceProviderRequests())->toBe(1)
        ->and($message->text_content)->toBe('النص الأول')
        ->and($message->transcription_attempts)->toBe(1);
});

it('does not re-open a voice note that already failed terminally', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $message = voiceNote($user, $account, [
        'transcription_status' => TranscriptionStatus::Failed,
        'transcription_failure_reason' => TranscriptionFailureReason::MediaExpired,
    ]);

    $outcome = voiceTranscribe($message);

    expect($outcome->result)->toBe(TranscriptionOutcome::SKIPPED)
        ->and($outcome->note)->toBe('already_settled')
        ->and(collect(Http::recorded()))->toBeEmpty();
});

it('leaves a live claim held by someone else alone', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $message = voiceNote($user, $account, [
        'transcription_status' => TranscriptionStatus::Processing,
        'transcription_claim_token' => str_repeat('a', 32),
        'transcription_claimed_at' => now(),
    ]);

    $outcome = voiceTranscribe($message);

    expect($outcome->result)->toBe(TranscriptionOutcome::SKIPPED)
        ->and($outcome->note)->toBe('claim_held')
        ->and(collect(Http::recorded()))->toBeEmpty();
});

it('takes over a claim whose lease has expired', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $message = voiceNote($user, $account, [
        'transcription_status' => TranscriptionStatus::Processing,
        'transcription_claim_token' => str_repeat('a', 32),
        'transcription_claimed_at' => now()->subSeconds(3600),
    ]);

    $outcome = voiceTranscribe($message);
    $message->refresh();

    expect($outcome->succeeded())->toBeTrue()
        ->and($message->transcription_attempts)->toBe(1)
        // The dead worker's token is gone: it can no longer settle anything.
        ->and($message->isTranscriptionClaimedBy(str_repeat('a', 32)))->toBeFalse();
});

it('releases the claim and rethrows a transient media failure for the queue to retry', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp(['metadata' => Http::response(['error' => 'boom'], 503)]);
    $message = voiceNote($user, $account);

    expect(fn () => voiceTranscribe($message))->toThrow(MediaFetchException::class);

    $message->refresh();

    // Back to pending, budget untouched: a network hiccup is not a spent
    // attempt, and how many times to retry it is the queue's decision.
    expect($message->transcription_status)->toBe(TranscriptionStatus::Pending)
        ->and($message->transcription_attempts)->toBe(0)
        ->and($message->transcription_claim_token)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The ledger
|--------------------------------------------------------------------------
*/

it('records voice usage on both dimensions and prices neither', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    voiceTranscribe($message);

    $rows = UsageEvent::query()->orderBy('type')->get();
    $perMessage = $rows->firstWhere('type', UsageDimension::VoiceMessage->value);
    $perMinute = $rows->firstWhere('type', UsageDimension::VoiceMinute->value);

    expect($rows)->toHaveCount(2)
        ->and($perMessage->quantity)->toBe(1)
        // 7.5 seconds is a STARTED minute: rounding down would record "no audio
        // was processed", which is not what happened.
        ->and($perMinute->quantity)->toBe(1)
        ->and($perMessage->duration_ms)->toBe(7500)
        ->and($perMessage->outcome)->toBe(UsageEventOutcome::Succeeded)
        ->and($perMessage->provider)->toBe('groq')
        ->and($perMessage->model)->toBe('test-transcribe');

    // UNPRICED, deliberately: transcription is billed per minute of audio and
    // Sanad has no per-minute pricing, so a number here would be a WRONG one
    // rather than a missing one. `none` is what finance reads as unknown cost.
    foreach ([$perMessage, $perMinute] as $row) {
        expect($row->cost_source)->toBe(CostSource::None)
            ->and((float) $row->total_cost)->toBe(0.0)
            ->and($row->model_price_id)->toBeNull();
    }
});

it('writes one ledger row per dimension per physical attempt, never one per retry of the same request', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    voiceTranscribe($message);
    voiceTranscribe($message->refresh());   // replay-guarded: no second request

    expect(UsageEvent::count())->toBe(2)
        ->and(voiceProviderRequests())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| What the subscriber reads
|--------------------------------------------------------------------------
*/

it('answers a refusal with one localized sentence and never a translation key', function () {
    [$user, $account] = voiceSubscriber(withVoice: false);
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    app()->call([new TranscribeVoiceNote($message->getKey()), 'handle']);

    $reply = Message::query()->firstWhere('in_reply_to_message_id', $message->getKey());

    expect($reply)->not->toBeNull()
        ->and($reply->text_content)->not->toContain('voice.failure')
        ->and($reply->text_content)->toBe(trans('voice.failure.voice_not_in_plan', [], 'ar'))
        // The code is for operators; the sentence is for the subscriber.
        ->and($reply->metadata['voice_failure_reason'])->toBe('voice_not_in_plan')
        // Delivered by the ordinary reply path, not a second sender.
        ->and(Queue::pushed(ProcessInboundMessage::class))->toHaveCount(1);
});

it('gives a voice note exactly one reply however many times the job runs', function () {
    [$user, $account] = voiceSubscriber(withVoice: false);
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    app()->call([new TranscribeVoiceNote($message->getKey()), 'handle']);
    app()->call([new TranscribeVoiceNote($message->getKey()), 'handle']);

    expect(Message::query()->where('in_reply_to_message_id', $message->getKey())->count())->toBe(1);
});

it('has a sentence for every failure reason in both locales', function () {
    foreach (TranscriptionFailureReason::cases() as $reason) {
        foreach (['ar', 'en'] as $locale) {
            $line = trans($reason->translationKey(), [], $locale);

            expect($line)->toBeString()
                ->and($line)->not->toBe($reason->translationKey())
                ->and(trim($line))->not->toBe('');
        }
    }
});

it('never puts a provider message, status code, model or credential in the reply', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp(['transcription' => Http::response(['error' => ['message' => 'INTERNAL_TRACE_xyz']], 400)]);
    $message = voiceNote($user, $account);

    app()->call([new TranscribeVoiceNote($message->getKey()), 'handle']);

    $reply = Message::query()->firstWhere('in_reply_to_message_id', $message->getKey());

    expect($reply->text_content)->not->toContain('INTERNAL_TRACE_xyz')
        ->and($reply->text_content)->not->toContain('400')
        ->and($reply->text_content)->not->toContain('groq')
        ->and($reply->text_content)->not->toContain('test-transcribe')
        ->and($reply->text_content)->not->toContain('test-groq-key')
        ->and(json_encode($message->refresh()->getAttributes(), JSON_UNESCAPED_UNICODE))
        ->not->toContain('test-groq-key');
});

/*
|--------------------------------------------------------------------------
| The audio itself
|--------------------------------------------------------------------------
*/

it('deletes the downloaded audio on success and on failure alike', function (callable $arrange) {
    [$user, $account] = voiceSubscriber();
    $arrange();
    $message = voiceNote($user, $account);

    try {
        voiceTranscribe($message);
    } catch (MediaFetchException) {
        // A transient failure is rethrown by design; the file still goes.
    }

    $directory = Storage::disk((string) config('voice.temp_disk'))->path((string) config('voice.temp_directory'));
    $left = is_dir($directory) ? array_values(array_diff((array) scandir($directory), ['.', '..'])) : [];

    expect($left)->toBe([])
        // And it was never recorded as if it survived.
        ->and($message->refresh()->media_path)->toBeNull();
})->with([
    'transcribed' => [fn () => voiceFakeHttp()],
    'provider refused' => [fn () => voiceFakeHttp(['transcription' => Http::response([], 400)])],
    'unreadable audio' => [fn () => voiceFakeHttp(['audio' => 'not ogg'])],
    'too long' => [function () {
        config(['voice.max_duration_seconds' => 1]);
        voiceFakeHttp();
    }],
]);

it('never exposes the signed media URL or the access token on the message', function () {
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    voiceTranscribe($message);

    $stored = json_encode($message->refresh()->getAttributes(), JSON_UNESCAPED_UNICODE);

    // The signed URL is itself a credential. It is read once, inside the media
    // client, and reaches nothing that is stored or rendered.
    expect($stored)->not->toContain('lookaside.example')
        ->and($stored)->not->toContain('TEST_ACCESS_TOKEN')
        ->and($stored)->not->toContain('test-groq-key');
});

it('stages a refusal reply idempotently', function () {
    [$user, $account] = voiceSubscriber();
    $message = voiceNote($user, $account);
    $staging = app(VoiceRefusalReply::class);

    $first = $staging->stage($message, TranscriptionFailureReason::MediaExpired);
    $second = $staging->stage($message, TranscriptionFailureReason::Internal);

    // One inbound, one reply — enforced by the unique constraint, not by a
    // check-then-insert that two workers could both pass.
    expect($second->getKey())->toBe($first->getKey())
        ->and($second->text_content)->toBe($first->text_content);
});
