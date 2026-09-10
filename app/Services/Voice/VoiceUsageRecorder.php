<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Data\Ai\Catalog\ResolvedRoute;
use App\Data\Billing\UsageRecord;
use App\Enums\UsageDimension;
use App\Enums\UsageEventOutcome;
use App\Models\Message;
use App\Services\Billing\UsageRecorder;
use App\Support\Billing\UsageKeys;
use App\Support\SafeError;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what a transcription provider ACTUALLY served: two ledger rows per
 * answered physical request — one `voice_message` (the request) and one
 * `voice_minute` (the audio it processed).
 *
 * ── WHY TWO DIMENSIONS ───────────────────────────────────────────────────────
 * Transcription is priced PER MINUTE OF AUDIO by every provider Sanad might
 * use. Recording only a per-request row would quietly redefine the unit and
 * make a 10-second note and a 5-minute note look identical in the ledger, which
 * is precisely the fiction that later turns into a wrong cost. So the minutes
 * are recorded as minutes, on their own dimension, alongside the request count.
 *
 * ── WHY THESE ROWS ARE UNPRICED ──────────────────────────────────────────────
 * They carry no monetary amount, and that is DELIBERATE, not an oversight.
 * CostCalculator prices per-token model prices and per-event configured rates;
 * a per-minute price would today be flattened into a flat per-request amount
 * (CostCalculator::tokenCost), which would be a WRONG number rather than a
 * missing one. Recording them unpriced makes them show up as `cost_source =
 * none`, which the finance surfaces already read as UNKNOWN COST — never as a
 * free operation. Pricing them correctly needs a finance change, and finance
 * schema is not this phase's to touch.
 *
 * ── WHAT IS NEVER WRITTEN ────────────────────────────────────────────────────
 * A row for a request whose outcome is unknown, and a row for a request the
 * provider positively refused without processing. As with reminder delivery,
 * the ABSENCE of a row is not a statement that the attempt cost nothing: an
 * unknown request may have been processed and billed without Sanad ever
 * learning of it, and reconciliation must read a missing row as UNKNOWN.
 *
 * NO QUOTA IS CHARGED here. Metering voice is not enforcing it, and enforcement
 * is a deliberate product decision that has not been taken.
 */
class VoiceUsageRecorder
{
    public function __construct(private readonly UsageRecorder $recorder) {}

    /**
     * @param  int  $attempt  the message's own physical attempt counter — server
     *                        owned, never derived from counting existing rows
     * @param  bool  $succeeded  whether the transcript reached the subscriber's
     *                           message; the provider consumed either way
     */
    public function record(
        Message $message,
        ResolvedRoute $route,
        int $attempt,
        int $durationMs,
        bool $succeeded,
    ): void {
        $correlationId = UsageKeys::correlationForMessage($message);
        $outcome = $succeeded ? UsageEventOutcome::Succeeded : UsageEventOutcome::DownstreamFailed;

        $metadata = [
            'message_id' => $message->getKey(),
            'attempt' => $attempt,
            'duration_ms' => $durationMs,
            'bytes' => $message->voice_bytes,
            // Why the row carries no amount. Stated on the row itself so a
            // future reader does not have to reconstruct the reason.
            'cost_note' => 'transcription is priced per minute of audio; Sanad has no per-minute pricing yet, so this row is recorded UNPRICED rather than with a fabricated amount',
        ];

        foreach ([
            [UsageDimension::VoiceMessage, 1],
            [UsageDimension::VoiceMinute, self::minutes($durationMs)],
        ] as [$dimension, $quantity]) {
            $this->write($message, $route, $correlationId, $attempt, $dimension, $quantity, $durationMs, $outcome, $metadata);
        }
    }

    /**
     * Audio minutes, ROUNDED UP. Providers bill a started minute, so rounding
     * down would under-report what we consumed — and a 10-second note would
     * record as zero minutes, which reads as "no audio was processed".
     */
    private static function minutes(int $durationMs): int
    {
        return max(1, (int) ceil($durationMs / 60000));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function write(
        Message $message,
        ResolvedRoute $route,
        string $correlationId,
        int $attempt,
        UsageDimension $dimension,
        int $quantity,
        int $durationMs,
        UsageEventOutcome $outcome,
        array $metadata,
    ): void {
        try {
            $this->recorder->record(new UsageRecord(
                subscriber: $message->user,
                dimension: $dimension,
                // One key per dimension per PHYSICAL attempt: a second genuine
                // request is a second row, because the provider served both.
                idempotencyKey: UsageKeys::deliveryAttempt($dimension, $correlationId, $attempt),
                correlationId: $correlationId,
                operation: 'voice:transcribe',
                provider: $route->provider->name(),
                model: $route->model,
                channel: $message->conversation?->channelAccount?->channel->value,
                quantity: $quantity,
                durationMs: $durationMs,
                outcome: $outcome,
                metadata: $metadata,
            ));
        } catch (Throwable $e) {
            // The provider request already happened. A ledger failure must
            // never change what the subscriber receives, so it is reported and
            // not rethrown.
            Log::error('sanad.voice.usage_not_recorded', [
                'message_id' => $message->getKey(),
                'dimension' => $dimension->value,
                'attempt' => $attempt,
                'error' => SafeError::summarize($e),
            ]);
        }
    }
}
