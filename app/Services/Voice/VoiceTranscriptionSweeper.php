<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Enums\TranscriptionStatus;
use App\Jobs\TranscribeVoiceNote;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * Recovers voice notes that stopped moving.
 *
 * Two shapes get stuck, and both are the same problem seen from either side of
 * a crash:
 *
 *   processing, past its lease — a worker took the claim and never came back.
 *   pending, and old            — a job was released or lost before it claimed.
 *
 * The sweeper does not transcribe, settle or reply. It only re-queues the job,
 * because every rule that matters — is the claim stale, is the budget spent, is
 * there already a transcript — is enforced inside the claim under a row lock.
 * Duplicating any of that here would create a second opinion about ownership,
 * and two opinions about ownership is how a voice note gets paid for twice.
 *
 * Re-queuing a row a live worker still owns is therefore safe and boring: that
 * worker holds the claim, the new job reads `claim_held` and stops.
 */
class VoiceTranscriptionSweeper
{
    /**
     * @return array{recovered: int}
     */
    public function sweep(): array
    {
        $lease = max(30, (int) config('voice.lease_seconds', 300));
        $cutoff = now()->subSeconds($lease);

        $ids = Message::query()
            ->whereNotNull('voice_media_id')
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where(fn ($q) => $q
                        ->where('transcription_status', TranscriptionStatus::Processing->value)
                        ->where('transcription_claimed_at', '<=', $cutoff))
                    ->orWhere(fn ($q) => $q
                        ->where('transcription_status', TranscriptionStatus::Pending->value)
                        ->where('created_at', '<=', $cutoff));
            })
            ->orderBy('id')
            ->limit(max(1, (int) config('voice.batch', 50)))
            ->pluck('id');

        foreach ($ids as $id) {
            TranscribeVoiceNote::dispatch((int) $id);
        }

        if ($ids->isNotEmpty()) {
            Log::info('sanad.voice.sweep', ['recovered' => $ids->count()]);
        }

        return ['recovered' => $ids->count()];
    }
}
