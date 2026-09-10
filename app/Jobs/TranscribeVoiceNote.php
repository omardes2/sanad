<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\Voice\TranscriptionOutcome;
use App\Enums\TranscriptionFailureReason;
use App\Enums\TranscriptionStatus;
use App\Models\Message;
use App\Services\Voice\VoiceNoteTranscriber;
use App\Services\Voice\VoiceRefusalReply;
use App\Support\SafeError;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transcribes one inbound voice note, then hands it to the ordinary reply path.
 *
 * ── WHY THIS IS NOT `ShouldBeUnique` ─────────────────────────────────────────
 * Every other job in this pipeline is, and this one deliberately is not.
 * Ownership here is the DATABASE CLAIM TOKEN, which survives a crashed worker,
 * a redelivered job and a second host — things a queue lock does not. Adding
 * `ShouldBeUnique` on top would buy nothing and cost something real: the lock is
 * still held while `handle()` runs, so the second physical attempt this job
 * dispatches for itself after an UNKNOWN outcome would be silently swallowed,
 * and the retry budget the phase promises would quietly become one.
 *
 * ── WHAT `tries` BOUNDS ──────────────────────────────────────────────────────
 * Only the free part: fetching the audio. A transient media failure is rethrown
 * by the transcriber so the queue backs off and tries again, and `failed()`
 * settles the message when that runs out. The PAID part has its own, stricter
 * bound — at most two physical provider requests per voice note, enforced in
 * the database under a row lock, and completely independent of how many times
 * this job runs.
 *
 * ── EVERY PATH ENDS IN ONE REPLY ─────────────────────────────────────────────
 * Transcribed → the agent answers the words. Refused → one localized sentence
 * saying why. Both go out through ProcessInboundMessage, so a voice note never
 * disappears silently, which is precisely what happened to it before this phase.
 */
class TranscribeVoiceNote implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(public int $messageId)
    {
        $this->onQueue((string) config('voice.queue', 'voice'));
    }

    public function handle(VoiceNoteTranscriber $transcriber, VoiceRefusalReply $reply): void
    {
        $outcome = $transcriber->transcribe($this->messageId);

        Log::info('sanad.voice.transcription', [
            'message_id' => $this->messageId,
            'result' => $outcome->result,
            'reason' => $outcome->reason?->value,
            'provider_requested' => $outcome->providerRequested,
        ]);

        if ($outcome->succeeded()) {
            // The message now has words. From here it is an ordinary inbound
            // message and the reply path needs to know nothing about audio.
            ProcessInboundMessage::dispatch($this->messageId)->onQueue('messages');

            return;
        }

        if ($outcome->result === TranscriptionOutcome::UNKNOWN) {
            // A paid request was authorised and its outcome was never proven.
            // The remaining budget allows exactly one more physical attempt;
            // the database enforces that, not this line.
            self::dispatch($this->messageId)->delay(now()->addSeconds(15));

            return;
        }

        if ($outcome->result === TranscriptionOutcome::FAILED && $outcome->reason !== null) {
            $this->answerWithRefusal($reply, $outcome->reason);
        }

        // SKIPPED means another owner is handling this voice note, or it was
        // already settled. Saying nothing is correct: whoever owns it replies.
    }

    /**
     * Stage the one bounded, localized sentence and let the ordinary reply path
     * deliver it.
     */
    private function answerWithRefusal(VoiceRefusalReply $reply, TranscriptionFailureReason $reason): void
    {
        /** @var Message|null $message */
        $message = Message::with('user')->find($this->messageId);

        if ($message === null) {
            return;
        }

        $reply->stage($message, $reason);

        // ProcessInboundMessage reuses an existing reply row without invoking
        // the agent, so this delivers our sentence rather than generating one.
        ProcessInboundMessage::dispatch($this->messageId)->onQueue('messages');
    }

    /**
     * The queue gave up — every `tries` attempt failed to even fetch the audio.
     *
     * The voice note is settled here rather than left in `pending` forever:
     * a subscriber who spoke to Sanad is owed an answer, and "we could not
     * download it, please send it again" is an answer. Nothing here asserts
     * anything about the transcription provider, which was never reached.
     */
    public function failed(?Throwable $exception): void
    {
        $safe = SafeError::summarize($exception);

        /** @var Message|null $message */
        $message = Message::with('user')->find($this->messageId);

        if ($message === null) {
            Log::warning('sanad.voice.transcription_failed', [
                'message_id' => $this->messageId,
                'error' => $safe,
                'missing' => true,
            ]);

            return;
        }

        // A transcript that arrived on a previous attempt is never overwritten
        // by a later attempt's failure.
        if (! $message->hasTranscript() && $message->transcription_status !== TranscriptionStatus::Failed) {
            $message->forceFill([
                'transcription_status' => TranscriptionStatus::Failed,
                'transcription_failure_reason' => TranscriptionFailureReason::MediaDownloadFailed,
                'transcription_claim_token' => null,
                'transcription_claimed_at' => null,
            ])->save();

            app(VoiceRefusalReply::class)->stage($message, TranscriptionFailureReason::MediaDownloadFailed);
            ProcessInboundMessage::dispatch($this->messageId)->onQueue('messages');
        }

        Log::warning('sanad.voice.transcription_failed', [
            'message_id' => $this->messageId,
            'error' => $safe,
        ]);
    }
}
