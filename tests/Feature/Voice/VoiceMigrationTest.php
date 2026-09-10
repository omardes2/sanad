<?php

declare(strict_types=1);

use App\Enums\MessageType;
use App\Enums\TranscriptionFailureReason;
use App\Enums\TranscriptionStatus;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Voice notes ship exactly ONE migration, and it is additive: columns and one
 * index on `messages`, a table that has existed since Sprint 0.
 *
 * What it deliberately does NOT add is a transcript column. The transcript goes
 * in the existing `text_content`, because `type = audio` already says the words
 * came from speech — and two authoritative copies of one sentence drift until
 * the copy the AI reads disagrees with the copy the admin shows.
 */
it('is the 65th of 66 migrations, adds only the voice columns, and rolls back and forward cleanly', function () {
    $files = glob(database_path('migrations/*.php'));

    expect($files)->toHaveCount(66)
        ->and(basename($files[64]))->toBe('2026_09_11_000101_add_voice_transcription_to_messages_table.php')
        ->and(Schema::hasColumn('messages', 'voice_media_id'))->toBeTrue()
        ->and(Schema::hasColumn('messages', 'transcription_status'))->toBeTrue()
        ->and(Schema::hasColumn('messages', 'transcription_claim_token'))->toBeTrue()
        ->and(Schema::hasIndex('messages', 'messages_transcription_status_claimed_idx'))->toBeTrue()
        // No rival home for the transcript, on purpose.
        ->and(Schema::hasColumn('messages', 'transcript'))->toBeFalse()
        ->and(Schema::hasColumn('messages', 'transcript_text'))->toBeFalse();

    $columns = collect(Schema::getColumns('messages'))->pluck('name')->all();
    sort($columns);

    expect($columns)->toBe([
        'conversation_id', 'created_at', 'delivered_at', 'delivery_error_code', 'delivery_status',
        'direction', 'external_message_id', 'id', 'in_reply_to_message_id', 'media_path', 'metadata',
        'processed_at', 'processing_status', 'provider_message_id', 'read_at', 'reminder_id', 'sent_at',
        'text_content', 'transcribed_at', 'transcription_attempts', 'transcription_claim_token',
        'transcription_claimed_at', 'transcription_dispatched_at', 'transcription_failure_reason',
        'transcription_model', 'transcription_provider', 'transcription_status', 'type', 'updated_at',
        'user_id', 'voice_bytes', 'voice_duration_ms', 'voice_media_id', 'voice_mime_type',
    ]);

    [$user, $account] = voiceSubscriber();
    $message = voiceNote($user, $account, ['text_content' => 'رسالة قبل التراجع']);

    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

    expect(Schema::hasColumn('messages', 'voice_media_id'))->toBeFalse()
        ->and(Schema::hasColumn('messages', 'transcription_status'))->toBeFalse()
        // Every earlier phase survives, and so does the message itself.
        ->and(Schema::hasTable('messages'))->toBeTrue()
        ->and(Schema::hasColumn('messages', 'reminder_id'))->toBeTrue()
        ->and(Schema::hasColumn('memories', 'fingerprint'))->toBeTrue()
        ->and(Schema::hasTable('tool_invocations'))->toBeTrue()
        ->and(DB::table('messages')->where('id', $message->id)->value('text_content'))->toBe('رسالة قبل التراجع')
        ->and(DB::table('migrations')->count())->toBe(64);

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasColumn('messages', 'voice_media_id'))->toBeTrue()
        ->and(Schema::hasIndex('messages', 'messages_transcription_status_claimed_idx'))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(66)
        // A message that pre-dates the columns is simply not a voice note.
        ->and(DB::table('messages')->where('id', $message->id)->value('transcription_status'))->toBeNull();
});

it('keeps a voice note coherent at the DATABASE level, not merely by convention', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('CHECK constraints are asserted on PostgreSQL, the production engine.');
    }

    [$user, $account] = voiceSubscriber();
    $message = voiceNote($user, $account);

    /*
     | Each attempt runs inside its OWN savepoint. On PostgreSQL a failed
     | statement aborts the surrounding transaction, so without this the first
     | refusal would take the rest of the test down with it — and the test would
     | be asserting the transaction was broken rather than that the constraint
     | works.
     */
    $refused = function (array $update) use ($message): bool {
        try {
            DB::transaction(fn () => DB::table('messages')->where('id', $message->id)->update($update));

            return false;
        } catch (QueryException) {
            return true;
        }
    };

    // A lifecycle state outside the enum.
    expect($refused(['transcription_status' => 'halfway']))->toBeTrue()
        // "Transcribed" with no time it was produced.
        ->and($refused([
            'transcription_status' => TranscriptionStatus::Transcribed->value,
            'transcribed_at' => null,
        ]))->toBeTrue()
        // "Failed" with no reason: a terminal failure always names why.
        ->and($refused([
            'transcription_status' => TranscriptionStatus::Failed->value,
            'transcription_failure_reason' => null,
        ]))->toBeTrue()
        // More attempts than the phase authorises. The application enforces this
        // under a row lock; the database refuses it whatever writes the row.
        ->and($refused(['transcription_attempts' => 3]))->toBeTrue()
        // And the coherent shape is accepted.
        ->and($refused([
            'transcription_status' => TranscriptionStatus::Failed->value,
            'transcription_failure_reason' => TranscriptionFailureReason::MediaExpired->value,
            'transcription_attempts' => 2,
        ]))->toBeFalse();

    expect(DB::table('messages')->where('id', $message->id)->value('transcription_attempts'))->toBe(2);
});

it('leaves every non-voice message untouched by the new columns', function () {
    [$user, $account] = voiceSubscriber();
    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);

    $text = Message::factory()->for($user)->for($conversation)->create([
        'type' => MessageType::Text,
        'text_content' => 'مرحبا',
    ]);

    expect($text->transcription_status)->toBeNull()
        ->and($text->voice_media_id)->toBeNull()
        // Read back from the database: the column's own default, not something
        // the model happened to have in memory.
        ->and($text->refresh()->transcription_attempts)->toBe(0)
        ->and($text->isVoiceNote())->toBeFalse();
});
