<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Reminder delivery ships exactly ONE migration, and it is additive: the claim
 * token, two nullable timestamps and an index on `reminders`, one nullable
 * unique FK on `messages`, and nothing else in E0–E5 or F1–F4 touched.
 */
it('is the 63rd of 64 migrations, adds only the delivery columns, and rolls back and forward cleanly', function () {
    $files = glob(database_path('migrations/*.php'));

    expect($files)->toHaveCount(64)
        ->and(basename($files[count($files) - 2]))->toBe('2026_09_09_000101_add_reminder_delivery_to_reminders_and_messages.php')
        ->and(Schema::hasColumn('reminders', 'claim_token'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'claimed_at'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'dispatched_at'))->toBeTrue()
        ->and(Schema::hasColumn('messages', 'reminder_id'))->toBeTrue()
        ->and(Schema::hasIndex('reminders', 'reminders_status_claimed_idx'))->toBeTrue()
        ->and(Schema::hasIndex('messages', 'messages_reminder_id_unique'))->toBeTrue();

    // The Sprint-0 columns this phase finally gives a writer are untouched.
    $columns = collect(Schema::getColumns('reminders'))->pluck('name')->all();
    sort($columns);
    expect($columns)->toBe([
        'attempts', 'channel', 'claim_token', 'claimed_at', 'created_at', 'dispatched_at', 'id',
        'last_error', 'remind_at', 'sent_at', 'source_message_id', 'status', 'task_id', 'timezone',
        'title', 'updated_at', 'user_id',
    ]);

    $user = User::factory()->create();
    Reminder::query()->create([
        'user_id' => $user->id,
        'title' => 'اختبار',
        'remind_at' => CarbonImmutable::now()->addHour(),
        'timezone' => 'Asia/Hebron',
        'channel' => ChannelType::WhatsApp->value,
        'status' => ReminderStatus::Pending->value,
    ]);

    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

    expect(Schema::hasColumn('reminders', 'claim_token'))->toBeFalse()
        ->and(Schema::hasColumn('reminders', 'claimed_at'))->toBeFalse()
        ->and(Schema::hasColumn('reminders', 'dispatched_at'))->toBeFalse()
        ->and(Schema::hasColumn('messages', 'reminder_id'))->toBeFalse()
        // Every earlier phase survives, and so does the reminder itself.
        ->and(Schema::hasTable('reminders'))->toBeTrue()
        ->and(Schema::hasTable('tool_invocations'))->toBeTrue()
        ->and(Schema::hasTable('finance_period_closes'))->toBeTrue()
        ->and(Schema::hasTable('usage_events'))->toBeTrue()
        ->and(DB::table('reminders')->count())->toBe(1)
        ->and(DB::table('migrations')->count())->toBe(62);

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasColumn('reminders', 'claim_token'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'claimed_at'))->toBeTrue()
        ->and(Schema::hasIndex('messages', 'messages_reminder_id_unique'))->toBeTrue()
        ->and(DB::table('reminders')->count())->toBe(1)
        ->and(DB::table('migrations')->count())->toBe(64);
});

it('enforces at most one outbound message per reminder at the database level', function () {
    [, , $conversation, $reminder] = reminderSubject();

    $row = [
        'conversation_id' => $conversation->id,
        'user_id' => $reminder->user_id,
        'direction' => 'outbound',
        'type' => 'text',
        'reminder_id' => $reminder->id,
        'text_content' => 'تذكير',
        'processing_status' => 'queued',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('messages')->insert($row);

    // Not a service rule: the database itself refuses the second message. The
    // duplicate runs in a SAVEPOINT (nested transaction) so PostgreSQL's
    // aborted-transaction state rolls back with it and the test can continue.
    expect(fn () => DB::transaction(fn () => DB::table('messages')->insert($row)))
        ->toThrow(UniqueConstraintViolationException::class);

    // And NULL is unconstrained, so ordinary messages are unaffected.
    DB::table('messages')->insert(array_merge($row, ['reminder_id' => null]));
    DB::table('messages')->insert(array_merge($row, ['reminder_id' => null]));

    expect(DB::table('messages')->whereNull('reminder_id')->count())->toBe(3);
});
