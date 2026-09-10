<?php

declare(strict_types=1);

use App\Enums\TranscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Voice notes: the operational facts needed to transcribe ONE inbound audio
 * message safely and asynchronously.
 *
 * WHAT IS DELIBERATELY ABSENT: a transcript column. The transcript goes in the
 * existing `text_content`, because `type = audio` already says the text came
 * from transcription rather than typing. Two authoritative copies of the same
 * sentence drift, and the one the AI path reads would eventually disagree with
 * the one the admin shows — so there is exactly one, and it inherits every
 * protection typed message content already has (`messages.content.view`).
 *
 * `media_path` is untouched and stays NULL for voice notes: the downloaded
 * audio is deleted seconds after it arrives, and a column pointing at a file we
 * intentionally removed is worse than an empty one. The durable reference the
 * queue needs for recovery is `voice_media_id`, the PROVIDER's media id, which
 * is a named thing rather than an abused path column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // The provider's media reference. Durable across a queue retry, and
            // the only thing needed to fetch the audio again before it expires.
            $table->string('voice_media_id', 191)->nullable()->after('media_path');
            $table->string('voice_mime_type', 128)->nullable()->after('voice_media_id');
            $table->unsignedBigInteger('voice_bytes')->nullable()->after('voice_mime_type');
            // Measured from the downloaded bytes BEFORE the provider request.
            $table->unsignedInteger('voice_duration_ms')->nullable()->after('voice_bytes');

            $table->string('transcription_status', 16)->nullable()->after('voice_duration_ms');
            $table->string('transcription_provider', 64)->nullable()->after('transcription_status');
            $table->string('transcription_model', 191)->nullable()->after('transcription_provider');

            // PHYSICAL provider requests. Bounded at `voice.max_attempts`.
            $table->unsignedTinyInteger('transcription_attempts')->default(0)->after('transcription_model');

            // Claim fencing, exactly as reminder delivery proved it: a
            // server-generated token is the compare-and-set that stops a stale
            // worker incrementing attempts or calling the provider. Queue
            // uniqueness is transport protection; this is durable ownership.
            $table->string('transcription_claim_token', 36)->nullable()->after('transcription_attempts');
            $table->timestamp('transcription_claimed_at')->nullable()->after('transcription_claim_token');
            $table->timestamp('transcription_dispatched_at')->nullable()->after('transcription_claimed_at');

            $table->string('transcription_failure_reason', 32)->nullable()->after('transcription_dispatched_at');
            $table->timestamp('transcribed_at')->nullable()->after('transcription_failure_reason');

            // The sweep's read: claimed rows ordered by when they were claimed.
            $table->index(['transcription_status', 'transcription_claimed_at'], 'messages_transcription_status_claimed_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $states = "'".implode("', '", TranscriptionStatus::values())."'";

            // The lifecycle is a code table; the database keeps the row coherent
            // whatever writes it.
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_transcription_status_check CHECK (transcription_status IS NULL OR transcription_status IN ({$states}))");
            // A transcript implies a time it was produced, and vice versa.
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_transcribed_at_check CHECK (transcription_status <> 'transcribed' OR transcribed_at IS NOT NULL)");
            // A terminal failure always names its bounded reason.
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_transcription_failed_check CHECK (transcription_status <> 'failed' OR transcription_failure_reason IS NOT NULL)");
            // Attempts can never exceed what the phase authorises.
            DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_transcription_attempts_check CHECK (transcription_attempts >= 0 AND transcription_attempts <= 2)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'messages_transcription_status_check',
                'messages_transcribed_at_check',
                'messages_transcription_failed_check',
                'messages_transcription_attempts_check',
            ] as $constraint) {
                DB::statement("ALTER TABLE messages DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_transcription_status_claimed_idx');
            $table->dropColumn([
                'voice_media_id',
                'voice_mime_type',
                'voice_bytes',
                'voice_duration_ms',
                'transcription_status',
                'transcription_provider',
                'transcription_model',
                'transcription_attempts',
                'transcription_claim_token',
                'transcription_claimed_at',
                'transcription_dispatched_at',
                'transcription_failure_reason',
                'transcribed_at',
            ]);
        });
    }
};
