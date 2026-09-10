<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Contracts\Ai\SupportsTranscription;
use App\Data\Ai\Catalog\ResolvedRoute;
use App\Data\Ai\TranscriptionRequest;
use App\Data\Voice\TranscriptionOutcome;
use App\Data\Voice\VoiceClaim;
use App\Enums\AiOperation;
use App\Enums\PlanFeature;
use App\Enums\TranscriptionFailureReason;
use App\Enums\TranscriptionStatus;
use App\Exceptions\Ai\AiEmptyResultException;
use App\Exceptions\Ai\AiException;
use App\Exceptions\WhatsApp\MediaFetchException;
use App\Models\Message;
use App\Services\Ai\SanadAiRouter;
use App\Services\Billing\SubscriptionService;
use App\Services\WhatsApp\WhatsAppMediaClient;
use App\Support\SafeError;
use App\Support\Voice\OggOpusDuration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns ONE voice note into text, or into a bounded reason why it did not.
 *
 * ── THE GUARANTEE ────────────────────────────────────────────────────────────
 * Bounded at-least-once, NOT exactly-once. A transcription provider can accept,
 * process and charge for audio while a crash between its response and our
 * settlement leaves us unable to prove it — that case is indistinguishable from
 * a request that never landed. So the guarantee is: AT MOST TWO physical
 * provider requests per voice note, ever, and a persisted transcript is what
 * prevents a replay. Claiming exactly-once here would be claiming something no
 * amount of code in this process can establish.
 *
 * ── OWNERSHIP ────────────────────────────────────────────────────────────────
 * Ownership is a server-generated CLAIM TOKEN, compared with hash_equals, taken
 * and re-verified inside short `lockForUpdate` transactions — the same fencing
 * reminder delivery uses. A queue's `ShouldBeUnique` is transport protection
 * that expires with its lock; this survives a crashed worker, a redelivered
 * job, and a second process on another host. A worker whose claim was swept and
 * re-issued discovers that BEFORE it increments the attempt counter and BEFORE
 * it reaches the provider, which is the whole point of putting the check there.
 *
 * ── ORDER OF REFUSALS ────────────────────────────────────────────────────────
 * Every cheap refusal happens before every expensive one:
 *
 *   configuration → plan → payload shape → routable model → media metadata
 *   → declared size → bytes → measured duration → PROVIDER REQUEST
 *
 * Everything left of the arrow into "PROVIDER REQUEST" is free, and
 * TranscriptionFailureReason::precedesPaidWork() states which reasons those
 * are, so the claim is testable rather than merely intended.
 *
 * ── THE AUDIO ────────────────────────────────────────────────────────────────
 * The bytes live on a private disk for the seconds it takes to measure and
 * upload them, and are deleted in a `finally` on success, failure and exception
 * alike. `messages.media_path` is never pointed at them: a column referring to
 * a file we deliberately delete is a lie the next reader has to discover.
 */
class VoiceNoteTranscriber
{
    public function __construct(
        private readonly WhatsAppMediaClient $media,
        private readonly SanadAiRouter $router,
        private readonly SubscriptionService $subscriptions,
        private readonly VoiceUsageRecorder $usage,
        private readonly VoiceTempStorage $storage,
    ) {}

    /**
     * Claim this voice note and run the pipeline once.
     *
     * Returns what happened; it never throws for an ordinary refusal. A
     * RETRYABLE media failure is rethrown so the queue — not this service —
     * owns that backoff, and the claim is released first so the retry can take
     * it cleanly.
     *
     * @throws MediaFetchException when the media could not be fetched and a retry may still work
     */
    public function transcribe(int $messageId): TranscriptionOutcome
    {
        $claim = $this->claim($messageId);

        if (is_string($claim)) {
            // The budget was already spent and the claim step closed the row.
            // Reported as the terminal outcome it is, so the caller still tells
            // the subscriber something rather than going quiet.
            return $claim === 'attempts_exhausted'
                ? TranscriptionOutcome::failed(TranscriptionFailureReason::TranscriptionUnknown, providerRequested: true)
                : TranscriptionOutcome::skipped($claim);
        }

        return $this->transcribeUnderClaim($claim);
    }

    /**
     * Do the work under a claim taken earlier.
     *
     * Separate from `claim()` on purpose: fencing is only meaningful if holding
     * a claim and acting on it can come apart in time, which is exactly what
     * happens when a worker stalls, its lease expires, and someone else takes
     * over. Every step below re-proves the token before it does anything that
     * cannot be undone.
     *
     * @throws MediaFetchException when the media could not be fetched and a retry may still work
     */
    public function transcribeUnderClaim(VoiceClaim $claim): TranscriptionOutcome
    {
        /** @var Message|null $message */
        $message = Message::query()->with('user')->find($claim->messageId);

        if ($message === null) {
            return TranscriptionOutcome::skipped('not_a_voice_note');
        }

        try {
            return $this->run($message, $claim->token);
        } catch (MediaFetchException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('sanad.voice.transcription_error', [
                'message_id' => $claim->messageId,
                'error' => SafeError::summarize($e),
            ]);

            return $this->settle($message, $claim->token, TranscriptionFailureReason::Internal, providerRequested: false);
        }
    }

    /**
     * Take exclusive ownership, or explain why not.
     *
     * Returns the claim, or a short reason string. Everything that decides
     * whether this voice note may be worked on at all is read INSIDE the row
     * lock, so two workers arriving together cannot both conclude yes.
     *
     * @return VoiceClaim|string
     */
    public function claim(int $messageId)
    {
        $token = bin2hex(random_bytes(16));

        return DB::transaction(function () use ($messageId, $token) {
            /** @var Message|null $message */
            $message = Message::query()->whereKey($messageId)->lockForUpdate()->first();

            if ($message === null || ! $message->isVoiceNote()) {
                return 'not_a_voice_note';
            }

            // A transcript already exists. This is the replay guard: the paid
            // work was done and its result persisted, so no amount of
            // redelivery can make us pay for the same audio twice.
            if ($message->hasTranscript()) {
                return 'already_transcribed';
            }

            if ($message->transcription_status === TranscriptionStatus::Failed) {
                return 'already_settled';
            }

            // Someone else holds a live claim. Not stale, not ours: leave it.
            if ($message->transcription_status === TranscriptionStatus::Processing
                && ! $message->hasStaleTranscriptionClaim()) {
                return 'claim_held';
            }

            if ($message->transcription_attempts >= $this->maxAttempts()) {
                // The budget is spent and the last outcome was never proven.
                // Recording that as `failed` with the UNKNOWN reason is the
                // honest shape: the row is closed, the truth is not asserted.
                $this->write($message, [
                    'transcription_status' => TranscriptionStatus::Failed,
                    'transcription_failure_reason' => TranscriptionFailureReason::TranscriptionUnknown,
                    'transcription_claim_token' => null,
                    'transcription_claimed_at' => null,
                ]);

                return 'attempts_exhausted';
            }

            $this->write($message, [
                'transcription_status' => TranscriptionStatus::Processing,
                'transcription_claim_token' => $token,
                'transcription_claimed_at' => now(),
                // A NEW claim authorises at most one paid request. Clearing this
                // is what makes "one dispatch per claim" a fact about the row
                // rather than a convention two code paths have to remember.
                'transcription_dispatched_at' => null,
                'transcription_failure_reason' => null,
            ]);

            return new VoiceClaim($messageId, $token);
        });
    }

    private function run(Message $message, string $token): TranscriptionOutcome
    {
        if (! (bool) config('voice.enabled', true)) {
            return $this->settle($message, $token, TranscriptionFailureReason::TranscriptionNotConfigured);
        }

        $subscriber = $message->user;

        // ENTITLEMENT, before anything external. A plan without voice never
        // causes a download, let alone a provider request.
        if ($subscriber === null || ! $this->subscriptions->hasFeature($subscriber, PlanFeature::Voice)) {
            return $this->settle($message, $token, TranscriptionFailureReason::VoiceNotInPlan);
        }

        // V1 is voice notes, not generic audio. An attached audio file is
        // refused here rather than silently widening the phase.
        if ($this->requiresVoiceFlag() && ($message->metadata['voice'] ?? null) !== true) {
            return $this->settle($message, $token, TranscriptionFailureReason::UnsupportedAudio);
        }

        // The MIME the channel declared at ingestion — the cheapest check of
        // all, made before a single request leaves this process.
        if (! $this->mimeAllowed($message->voice_mime_type)) {
            return $this->settle($message, $token, TranscriptionFailureReason::UnsupportedAudio);
        }

        // No routable transcription model ⇒ nothing downstream can succeed.
        // Discovering that before the download saves the bandwidth AND keeps
        // the failure honest: "not configured", not "download failed".
        $route = $this->route();

        if ($route === null) {
            return $this->settle($message, $token, TranscriptionFailureReason::TranscriptionNotConfigured);
        }

        $path = $this->storage->path($message);

        try {
            return $this->withAudio($message, $token, $route, $path);
        } finally {
            // Success, refusal, exception — the audio does not outlive this
            // method. There is no branch in which it is kept.
            $this->storage->forget($path);
        }
    }

    /**
     * The part of the pipeline that has bytes on disk.
     *
     * @throws MediaFetchException when a retry may still work
     */
    private function withAudio(Message $message, string $token, ResolvedRoute $route, string $path): TranscriptionOutcome
    {
        try {
            $metadata = $this->media->metadata((string) $message->voice_media_id);

            // The provider's own MIME, which can differ from the webhook's.
            if (! $this->mimeAllowed($metadata->mimeType)) {
                return $this->settle($message, $token, TranscriptionFailureReason::UnsupportedAudio);
            }

            $bytes = $this->media->download($metadata, $path, $this->maxBytes());
        } catch (MediaFetchException $e) {
            return $this->settleMediaFailure($message, $token, $e);
        }

        // Measured from the bytes we actually hold — never from a provider's
        // claim, and never after paying, because a duration ceiling that is
        // enforced after the request is not a ceiling.
        $durationMs = OggOpusDuration::milliseconds($path);

        if ($durationMs === null) {
            // The container did not say. Refusing is the only safe answer: a
            // guessed duration would let exactly the file the limit exists for
            // through the limit.
            return $this->settle($message, $token, TranscriptionFailureReason::UnsupportedAudio);
        }

        $this->write($message, ['voice_bytes' => $bytes, 'voice_duration_ms' => $durationMs]);

        if ($durationMs > $this->maxDurationSeconds() * 1000) {
            return $this->settle($message, $token, TranscriptionFailureReason::AudioTooLong);
        }

        // ── THE BOUNDARY ────────────────────────────────────────────────────
        // Everything above is free. Past this point a request may be charged
        // for, so the attempt is authorised under the lock first.
        if (! $this->authorise($message, $token)) {
            return TranscriptionOutcome::skipped('claim_lost');
        }

        return $this->callProvider($message, $token, $route, $path, $durationMs);
    }

    /**
     * Authorise ONE physical provider request: re-read under a row lock, prove
     * the claim is still ours and unspent, and increment the counter.
     *
     * This is the single place the physical attempt counter moves, and it moves
     * only together with `transcription_dispatched_at` under the same lock — so
     * "attempts ≤ 2" and "one paid request per claim" are the same fact, not two
     * that could drift apart.
     */
    private function authorise(Message $message, string $token): bool
    {
        return (bool) DB::transaction(function () use ($message, $token): bool {
            /** @var Message|null $fresh */
            $fresh = Message::query()->whereKey($message->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isTranscriptionClaimedBy($token)) {
                return false;   // Swept and re-issued: a stale worker stops here.
            }

            if ($fresh->transcriptionDispatchedUnderCurrentClaim()) {
                return false;   // This claim already spent its one request.
            }

            if ($fresh->transcription_attempts >= $this->maxAttempts()) {
                return false;   // The budget is spent. Never a third request.
            }

            $this->write($fresh, [
                'transcription_attempts' => $fresh->transcription_attempts + 1,
                'transcription_dispatched_at' => now(),
            ]);

            $message->setAttribute('transcription_attempts', $fresh->transcription_attempts);

            return true;
        });
    }

    private function callProvider(
        Message $message,
        string $token,
        ResolvedRoute $route,
        string $path,
        int $durationMs,
    ): TranscriptionOutcome {
        /** @var SupportsTranscription $provider */
        $provider = $route->provider;
        $attempt = (int) $message->transcription_attempts;

        try {
            $result = $provider->transcribe(new TranscriptionRequest(
                path: $path,
                mimeType: (string) ($message->voice_mime_type ?? 'audio/ogg'),
                spec: $route->spec,
                languageHint: $this->languageHint($message),
                durationMs: $durationMs,
                timeout: max(1, (int) config('voice.request_timeout', 25)),
            ));
        } catch (AiException $e) {
            if ($e->retryable()) {
                // UNKNOWN. The provider may have processed and charged for this
                // audio. It is never recorded as a failure and never as zero
                // cost; no ledger row is written, and the ABSENCE of a row must
                // be read as unknown — exactly as reminder delivery reads it.
                return $this->settleUnknown($message, $token);
            }

            // An EMPTY result means the provider did the work and produced
            // nothing usable, so the consumption is recorded. A 4xx means it
            // processed nothing, so nothing is recorded — and that absence is
            // read as "no consumption", which for a positive refusal is a fact
            // rather than an assumption.
            if ($e instanceof AiEmptyResultException) {
                $this->usage->record($message, $route, $attempt, $durationMs, succeeded: false);
            }

            return $this->settle(
                $message,
                $token,
                $e instanceof AiEmptyResultException
                    ? TranscriptionFailureReason::TranscriptEmpty
                    : TranscriptionFailureReason::TranscriptionFailed,
                providerRequested: true,
            );
        }

        // The provider answered and consumed. Recorded whether or not the rest
        // of this method succeeds — that consumption is real either way.
        $this->usage->record($message, $route, $attempt, $durationMs, succeeded: true);

        return $this->settleTranscript($message, $token, $result->text, $route, $result->language);
    }

    /**
     * Persist the transcript into the message's OWN text.
     *
     * There is no separate transcript column and no second message row: `type
     * = audio` already says the words came from speech rather than typing. Two
     * authoritative copies of one sentence drift, and the copy the AI path
     * reads would eventually disagree with the copy the admin shows.
     */
    private function settleTranscript(
        Message $message,
        string $token,
        string $text,
        ResolvedRoute $route,
        ?string $language,
    ): TranscriptionOutcome {
        $applied = DB::transaction(function () use ($message, $text, $route, $language): bool {
            /** @var Message|null $fresh */
            $fresh = Message::query()->whereKey($message->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return false;
            }

            // Another owner already produced a transcript. Ours is not written
            // over it: one voice note has exactly one transcript, and the first
            // one persisted is the one the subscriber was answered from.
            if ($fresh->hasTranscript()) {
                return false;
            }

            $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];

            if ($language !== null && $language !== '') {
                $metadata['transcript_language'] = $language;
            }

            $this->write($fresh, [
                'text_content' => $text,
                'metadata' => $metadata,
                'transcription_status' => TranscriptionStatus::Transcribed,
                'transcription_provider' => $route->provider->name(),
                'transcription_model' => $route->model,
                'transcription_failure_reason' => null,
                'transcribed_at' => now(),
                'transcription_claim_token' => null,
                'transcription_claimed_at' => null,
            ]);

            return true;
        });

        if (! $applied) {
            // We paid, someone else's transcript stands. The usage row is
            // already written; the message is left exactly as its owner left it.
            return TranscriptionOutcome::skipped('transcript_already_present');
        }

        $message->refresh();

        return TranscriptionOutcome::transcribed();
    }

    /**
     * A retryable outcome after a paid request: release the claim if budget
     * remains, settle terminally if it does not.
     */
    private function settleUnknown(Message $message, string $token): TranscriptionOutcome
    {
        $exhausted = (bool) DB::transaction(function () use ($message, $token): bool {
            /** @var Message|null $fresh */
            $fresh = Message::query()->whereKey($message->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isTranscriptionClaimedBy($token)) {
                return false;
            }

            if ($fresh->transcription_attempts >= $this->maxAttempts()) {
                $this->write($fresh, [
                    'transcription_status' => TranscriptionStatus::Failed,
                    'transcription_failure_reason' => TranscriptionFailureReason::TranscriptionUnknown,
                    'transcription_claim_token' => null,
                    'transcription_claimed_at' => null,
                ]);

                return true;
            }

            // Budget remains: back to pending for exactly one more physical try.
            $this->write($fresh, [
                'transcription_status' => TranscriptionStatus::Pending,
                'transcription_failure_reason' => TranscriptionFailureReason::TranscriptionUnknown,
                'transcription_claim_token' => null,
                'transcription_claimed_at' => null,
            ]);

            return false;
        });

        $message->refresh();

        return $exhausted
            ? TranscriptionOutcome::failed(TranscriptionFailureReason::TranscriptionUnknown, providerRequested: true)
            : TranscriptionOutcome::unknown();
    }

    /**
     * A media failure. Permanent ones settle; a transient one releases the
     * claim and is rethrown so the QUEUE owns the backoff — this service does
     * not sleep, and does not decide how many times a network hiccup is worth
     * retrying.
     *
     * @throws MediaFetchException
     */
    private function settleMediaFailure(Message $message, string $token, MediaFetchException $e): TranscriptionOutcome
    {
        if ($e->retryable()) {
            $this->release($message, $token);

            throw $e;
        }

        return $this->settle($message, $token, match ($e->kind) {
            MediaFetchException::KIND_GONE => TranscriptionFailureReason::MediaExpired,
            MediaFetchException::KIND_TOO_LARGE => TranscriptionFailureReason::AudioTooLarge,
            default => TranscriptionFailureReason::MediaDownloadFailed,
        });
    }

    /** Record a terminal outcome, only while the claim is still ours. */
    private function settle(
        Message $message,
        string $token,
        TranscriptionFailureReason $reason,
        bool $providerRequested = false,
    ): TranscriptionOutcome {
        DB::transaction(function () use ($message, $token, $reason): void {
            /** @var Message|null $fresh */
            $fresh = Message::query()->whereKey($message->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isTranscriptionClaimedBy($token) || $fresh->hasTranscript()) {
                return;
            }

            $this->write($fresh, [
                'transcription_status' => TranscriptionStatus::Failed,
                'transcription_failure_reason' => $reason,
                'transcription_claim_token' => null,
                'transcription_claimed_at' => null,
            ]);
        });

        $message->refresh();

        return TranscriptionOutcome::failed($reason, $providerRequested);
    }

    /** Hand the claim back untouched so another attempt can take it. */
    private function release(Message $message, string $token): void
    {
        DB::transaction(function () use ($message, $token): void {
            /** @var Message|null $fresh */
            $fresh = Message::query()->whereKey($message->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isTranscriptionClaimedBy($token)) {
                return;
            }

            $this->write($fresh, [
                'transcription_status' => TranscriptionStatus::Pending,
                'transcription_claim_token' => null,
                'transcription_claimed_at' => null,
            ]);
        });
    }

    /**
     * The routed transcription provider, or null when none can serve.
     *
     * A provider that does not implement SupportsTranscription is treated as no
     * route at all rather than called and allowed to fatal: the catalog is
     * operator data, and operator data must not be able to crash a worker.
     */
    private function route(): ?ResolvedRoute
    {
        try {
            $route = $this->router->route(AiOperation::Transcription);
        } catch (Throwable) {
            return null;
        }

        return $route->provider instanceof SupportsTranscription ? $route : null;
    }

    /**
     * The subscriber's language, as a HINT only. A hint is never a filter: a
     * subscriber may speak a language their locale does not name, and the
     * provider is free to detect otherwise.
     */
    private function languageHint(Message $message): ?string
    {
        $locale = trim((string) ($message->user?->locale ?? ''));

        if ($locale === '') {
            return null;
        }

        // "ar_SA" / "ar-SA" → "ar": providers expect the language, not the region.
        return strtolower(explode('-', str_replace('_', '-', $locale), 2)[0]) ?: null;
    }

    private function mimeAllowed(?string $mimeType): bool
    {
        if ($mimeType === null || trim($mimeType) === '') {
            return false;   // Unknown format is not a permitted format.
        }

        $base = strtolower(trim(explode(';', $mimeType, 2)[0]));

        foreach ((array) config('voice.allowed_mime_types', []) as $allowed) {
            if ($base === strtolower(trim(explode(';', (string) $allowed, 2)[0]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(Message $message, array $attributes): void
    {
        $message->forceFill($attributes)->save();
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('voice.max_attempts', 2));
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('voice.max_bytes', 8 * 1024 * 1024));
    }

    private function maxDurationSeconds(): int
    {
        return max(1, (int) config('voice.max_duration_seconds', 300));
    }

    private function requiresVoiceFlag(): bool
    {
        return (bool) config('voice.require_voice_flag', true);
    }
}
