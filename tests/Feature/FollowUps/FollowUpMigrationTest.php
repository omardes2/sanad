<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\FollowUpStatus;
use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Models\FollowUp;
use App\Models\Reminder;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('is the 67th and last migration, adds only the loop and the ask identity, and rolls back and forward cleanly', function () {
    $files = glob(database_path('migrations/*.php'));
    sort($files);

    expect($files)->toHaveCount(67)
        ->and(basename($files[66]))->toBe('2026_09_13_000101_create_follow_ups_and_ask_identity.php');

    expect(Schema::hasTable('follow_ups'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'follow_up_id'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'ask_index'))->toBeTrue()
        ->and(Schema::hasIndex('reminders', 'reminders_follow_up_ask_unique'))->toBeTrue();

    // The loop's own columns — and `expires_at` is absent because V1 has no
    // deadline concept, and `asks_sent` because the budget is derived from
    // reminder truth rather than counted here.
    $columns = collect(Schema::getColumns('follow_ups'))->pluck('name')->all();
    sort($columns);

    expect($columns)->toBe([
        'blocked_at', 'blocked_reason', 'channel', 'created_at', 'id', 'max_asks',
        'next_ask_at', 'question', 'resolved_at', 'resolved_by_message_id',
        'source_message_id', 'status', 'task_id', 'terminated_at', 'timezone',
        'updated_at', 'user_id', 'version',
    ])
        ->and(in_array('expires_at', $columns, true))->toBeFalse()
        ->and(in_array('asks_sent', $columns, true))->toBeFalse();

    /*
     * NO MESSAGE SCHEMA CHANGE, asserted on the migration's own source rather than
     * on a column's absence: reply correlation points FROM `follow_ups`
     * (`resolved_by_message_id`), so `messages` did not have to be touched to make
     * it possible. `in_reply_to_message_id` has existed on `messages` since
     * Sprint 0 and is NOT what correlation uses — an inbound WhatsApp reply
     * carries no reference to the message it answers.
     */
    $migration = file_get_contents(database_path('migrations/2026_09_13_000101_create_follow_ups_and_ask_identity.php'));

    expect(Schema::hasColumn('messages', 'follow_up_id'))->toBeFalse()
        ->and(str_contains($migration, "Schema::table('messages'"))->toBeFalse()
        ->and(str_contains($migration, "Schema::create('messages'"))->toBeFalse()
        // And no finance table either.
        ->and(str_contains($migration, 'usage_events'))->toBeFalse()
        ->and(str_contains($migration, 'cost_'))->toBeFalse();

    // One-time reminders and recurring occurrences are untouched.
    $reminderColumns = collect(Schema::getColumns('reminders'))->pluck('name')->all();

    expect($reminderColumns)->toContain('occurrence_key')
        ->and($reminderColumns)->toContain('claim_token')
        ->and(Schema::hasIndex('messages', 'messages_reminder_id_unique'))->toBeTrue();

    Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

    expect(Schema::hasTable('follow_ups'))->toBeFalse()
        ->and(Schema::hasColumn('reminders', 'follow_up_id'))->toBeFalse()
        ->and(Schema::hasColumn('reminders', 'ask_index'))->toBeFalse()
        // Every earlier phase survives.
        ->and(Schema::hasTable('reminder_schedules'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'occurrence_key'))->toBeTrue()
        ->and(Schema::hasColumn('messages', 'transcription_status'))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(66);

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasTable('follow_ups'))->toBeTrue()
        ->and(Schema::hasIndex('reminders', 'reminders_follow_up_ask_unique'))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(67);
});

it('makes the logical ask identity the duplicate authority', function () {
    fuConfigure();
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);

    $ask = fn (int $index): array => [
        'user_id' => $user->id,
        'follow_up_id' => $followUp->id,
        'ask_index' => $index,
        'title' => 'دفعت؟',
        'remind_at' => CarbonImmutable::now(),
        'timezone' => 'Asia/Hebron',
        'channel' => ChannelType::WhatsApp->value,
        'status' => ReminderStatus::Pending->value,
    ];

    Reminder::query()->create($ask(1));

    // The same ask twice is a DATABASE error, not a race the application has to
    // win: two materialisers cannot both insert it. The attempt runs inside its own
    // nested transaction because on PostgreSQL a failed statement aborts the
    // surrounding one — which is exactly why the materialiser uses `createOrFirst`
    // (a SAVEPOINT) rather than catching the violation.
    $duplicate = false;

    try {
        DB::transaction(fn () => Reminder::query()->create($ask(1)));
    } catch (QueryException) {
        $duplicate = true;
    }

    expect($duplicate)->toBeTrue();

    // A different index is a different ask, and another loop's ask 1 is unrelated.
    Reminder::query()->create($ask(2));
    $second = fuCreate($user, $conversation, ['question' => 'حكيت مع المدير؟']);
    Reminder::query()->create(array_merge($ask(1), ['follow_up_id' => $second->id]));

    expect(Reminder::query()->whereNotNull('follow_up_id')->count())->toBe(3);
});

it('leaves ordinary reminders entirely unconstrained by the ask identity', function () {
    fuConfigure();
    [$user, , $conversation] = fuSubscriber();
    $message = fuInbound($user, $conversation, 'ذكرني بكرا');

    // Many reminders with NULL on both columns: multiple NULLs are permitted by
    // the unique index on PostgreSQL and SQLite alike, which is the whole reason
    // one-time delivery is unaffected.
    foreach (range(1, 3) as $i) {
        Reminder::query()->create([
            'user_id' => $user->id,
            'source_message_id' => $message->id,
            'title' => "تذكير {$i}",
            'remind_at' => CarbonImmutable::now()->addHours($i),
            'timezone' => 'Asia/Hebron',
            'channel' => ChannelType::WhatsApp->value,
            'status' => ReminderStatus::Pending->value,
        ]);
    }

    expect(Reminder::query()->whereNull('follow_up_id')->count())->toBe(3);
});

it('keeps the loop coherent in the database on PostgreSQL, whatever writes it', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('CHECK constraints are asserted on PostgreSQL.');
    }

    fuConfigure();
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);

    // Each attempt runs in its OWN nested transaction: on PostgreSQL a failed
    // statement aborts the surrounding one, so a shared transaction would make
    // every assertion after the first fail for the wrong reason.
    $refused = function (array $attributes) use ($followUp): bool {
        try {
            DB::transaction(fn () => DB::table('follow_ups')
                ->where('id', $followUp->id)
                ->update($attributes));

            return false;
        } catch (QueryException) {
            return true;
        }
    };

    expect($refused(['status' => 'probably_done']))->toBeTrue('an unknown state')
        // V1 has no deadline, so there is no `expired` state to write.
        ->and($refused(['status' => 'expired', 'terminated_at' => CarbonImmutable::now()]))->toBeTrue('expired')
        // `blocked` and a reason are ONE fact; neither half is valid alone.
        ->and($refused(['status' => FollowUpStatus::Blocked->value]))->toBeTrue('blocked with no reason')
        ->and($refused(['blocked_reason' => 'template_unavailable', 'blocked_at' => CarbonImmutable::now()]))->toBeTrue('a reason with no block')
        ->and($refused(['blocked_reason' => 'because_i_said_so', 'blocked_at' => CarbonImmutable::now(), 'status' => FollowUpStatus::Blocked->value]))->toBeTrue('an unknown reason')
        // A terminal loop has a termination stamp, and a live one has none.
        ->and($refused(['status' => FollowUpStatus::Cancelled->value]))->toBeTrue('terminal with no stamp')
        ->and($refused(['terminated_at' => CarbonImmutable::now()]))->toBeTrue('a stamp while live')
        // Resolution evidence belongs to resolved loops only.
        ->and($refused(['resolved_at' => CarbonImmutable::now()]))->toBeTrue('resolved_at while open')
        // A finished loop never has another ask scheduled.
        ->and($refused([
            'status' => FollowUpStatus::Cancelled->value,
            'terminated_at' => CarbonImmutable::now(),
            'next_ask_at' => CarbonImmutable::now()->addDay(),
        ]))->toBeTrue('a next ask after termination')
        // The budget is a bound, so it must be one.
        ->and($refused(['max_asks' => 0]))->toBeTrue('a zero budget');

    // And the coherent writes are accepted.
    expect($refused([
        'status' => FollowUpStatus::Cancelled->value,
        'terminated_at' => CarbonImmutable::now(),
        'next_ask_at' => null,
    ]))->toBeFalse('a coherent cancellation');
});

it('keeps an ask\'s two identity columns together on PostgreSQL', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('CHECK constraints are asserted on PostgreSQL.');
    }

    fuConfigure();
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);
    $message = fuInbound($user, $conversation, 'ذكرني');

    $reminder = Reminder::query()->create([
        'user_id' => $user->id,
        'source_message_id' => $message->id,
        'title' => 'تذكير',
        'remind_at' => CarbonImmutable::now()->addHour(),
        'timezone' => 'Asia/Hebron',
        'channel' => ChannelType::WhatsApp->value,
        'status' => ReminderStatus::Pending->value,
    ]);

    $refused = function (array $attributes) use ($reminder): bool {
        try {
            DB::transaction(fn () => DB::table('reminders')->where('id', $reminder->id)->update($attributes));

            return false;
        } catch (QueryException) {
            return true;
        }
    };

    expect($refused(['follow_up_id' => $followUp->id]))->toBeTrue('a loop with no ask index')
        ->and($refused(['ask_index' => 1]))->toBeTrue('an ask index with no loop')
        ->and($refused(['follow_up_id' => $followUp->id, 'ask_index' => 0]))->toBeTrue('a zero ask index')
        ->and($refused(['follow_up_id' => $followUp->id, 'ask_index' => 7]))->toBeFalse('both together');
});

it('keeps the loop when the task it is attached to goes away, and survives account deletion', function () {
    fuConfigure();
    [$user, , $conversation] = fuSubscriber();

    $task = Task::query()->create([
        'user_id' => $user->id,
        'title' => 'ادفع',
        'status' => TaskStatus::Pending->value,
    ]);

    $followUp = fuCreate($user, $conversation, ['task_id' => $task->id]);
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    expect(fuAsks($followUp))->toHaveCount(1);

    // A deleted task leaves the loop standing (nullOnDelete): the subscriber still
    // asked to be followed up with.
    $task->delete();

    expect($followUp->fresh()->task_id)->toBeNull()
        ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open);

    /*
     * DELETING A SUBSCRIBER deletes everything of theirs, asks included — the path
     * that actually matters, and the only one that removes a loop: nothing in the
     * product deletes a `follow_ups` row, because stopping a loop is a STATUS
     * CHANGE (`cancelled`) that keeps the record of what was asked.
     */
    $askId = fuAsks($followUp)[0]->id;

    $user->delete();

    expect(FollowUp::query()->whereKey($followUp->getKey())->exists())->toBeFalse()
        ->and(Reminder::query()->whereKey($askId)->exists())->toBeFalse();
});
