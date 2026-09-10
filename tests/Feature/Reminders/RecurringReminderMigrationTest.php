<?php

declare(strict_types=1);

use App\Enums\ReminderScheduleStatus;
use App\Models\Reminder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Recurring reminders ship exactly ONE migration, and it is additive: a new
 * definition table plus three nullable columns and one unique index on
 * `reminders`, which has existed since Sprint 0.
 *
 * The nullability is the whole compatibility story. A one-time reminder keeps
 * NULL in all three, so it is not an occurrence, nothing materialises it, and the
 * delivery path cannot tell the difference — which is exactly why the dispatcher
 * needed no change.
 */
it('is the 66th migration, adds only the recurrence columns, and rolls back and forward cleanly', function () {
    $files = glob(database_path('migrations/*.php'));

    expect($files)->toHaveCount(67)
        ->and(basename($files[65]))->toBe('2026_09_12_000101_create_reminder_schedules_and_occurrences.php')
        ->and(Schema::hasTable('reminder_schedules'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'reminder_schedule_id'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'occurrence_key'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'occurrence_local_at'))->toBeTrue()
        ->and(Schema::hasIndex('reminders', 'reminders_schedule_occurrence_unique'))->toBeTrue();

    $columns = collect(Schema::getColumns('reminder_schedules'))->pluck('name')->all();
    sort($columns);

    expect($columns)->toBe([
        'channel', 'created_at', 'day_of_month', 'ends_on', 'id', 'local_time', 'materialised_through',
        'pattern', 'source_message_id', 'starts_on', 'status', 'terminated_at', 'timezone', 'title',
        'updated_at', 'user_id', 'version', 'weekdays',
    ]);

    // A schedule is a DEFINITION: it carries no delivery state at all, because the
    // moment a definition holds an attempt counter it has started serving many
    // sends from one row.
    foreach (['attempts', 'claim_token', 'claimed_at', 'dispatched_at', 'sent_at', 'remind_at', 'last_error'] as $deliveryColumn) {
        expect(Schema::hasColumn('reminder_schedules', $deliveryColumn))->toBeFalse();
    }

    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();
    $occurrences = Reminder::query()->where('reminder_schedule_id', $schedule->id)->count();

    expect($occurrences)->toBeGreaterThan(0);

    // TWO steps: the follow-up migration now sits on top of the recurrence one,
    // and it is the recurrence one this test is about.
    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

    expect(Schema::hasTable('reminder_schedules'))->toBeFalse()
        ->and(Schema::hasColumn('reminders', 'reminder_schedule_id'))->toBeFalse()
        ->and(Schema::hasColumn('reminders', 'occurrence_key'))->toBeFalse()
        ->and(Schema::hasColumn('reminders', 'follow_up_id'))->toBeFalse()
        // Every earlier phase survives, and so do the reminder rows themselves.
        ->and(Schema::hasTable('reminders'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'claim_token'))->toBeTrue()
        ->and(Schema::hasColumn('messages', 'transcription_status'))->toBeTrue()
        ->and(Schema::hasColumn('memories', 'fingerprint'))->toBeTrue()
        ->and(DB::table('reminders')->count())->toBe($occurrences)
        ->and(DB::table('migrations')->count())->toBe(65);

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasTable('reminder_schedules'))->toBeTrue()
        ->and(Schema::hasIndex('reminders', 'reminders_schedule_occurrence_unique'))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(67)
        // Rows that pre-date the columns are simply not occurrences.
        ->and(DB::table('reminders')->whereNull('reminder_schedule_id')->count())->toBe($occurrences);
});

it('lets the DATABASE enforce one occurrence per schedule, not merely the application', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    $existing = Reminder::query()->where('reminder_schedule_id', $schedule->id)->first();

    // The unique key is the authority for duplicate safety. Two materialisers
    // racing cannot both insert, whatever the application believes.
    expect(fn () => Reminder::query()->create([
        'user_id' => $user->id,
        'reminder_schedule_id' => $schedule->id,
        'occurrence_key' => $existing->occurrence_key,
        'title' => 'نسخة ثانية',
        'remind_at' => now()->addDay(),
        'timezone' => 'Asia/Hebron',
        'channel' => 'whatsapp',
        'status' => 'pending',
    ]))->toThrow(QueryException::class);
});

it('allows many one-time reminders to coexist with NULL occurrence identity', function () {
    [$user] = recSubscriber();

    // Multiple NULLs are permitted on PostgreSQL and SQLite alike, so the unique
    // index constrains occurrences only and leaves ordinary reminders alone.
    foreach (range(1, 3) as $i) {
        Reminder::query()->create([
            'user_id' => $user->id,
            'title' => "مفرد {$i}",
            'remind_at' => now()->addDays($i),
            'timezone' => 'Asia/Hebron',
            'channel' => 'whatsapp',
            'status' => 'pending',
        ]);
    }

    expect(Reminder::query()->whereNull('reminder_schedule_id')->count())->toBe(3);
});

it('keeps a schedule row coherent at the DATABASE level, not merely by convention', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('CHECK constraints are asserted on PostgreSQL, the production engine.');
    }

    [$user] = recSubscriber();
    $schedule = recSchedule($user);

    // Each attempt in its OWN savepoint: on PostgreSQL a failed statement aborts
    // the surrounding transaction, so without this the first refusal would take the
    // rest of the test with it and the test would be asserting the wrong thing.
    $refused = function (array $update) use ($schedule): bool {
        try {
            DB::transaction(fn () => DB::table('reminder_schedules')->where('id', $schedule->id)->update($update));

            return false;
        } catch (QueryException) {
            return true;
        }
    };

    expect($refused(['pattern' => 'hourly']))->toBeTrue()
        ->and($refused(['status' => 'paused']))->toBeTrue()
        // A terminated schedule always records when, and an active one never does.
        ->and($refused(['status' => ReminderScheduleStatus::Terminated->value, 'terminated_at' => null]))->toBeTrue()
        ->and($refused(['terminated_at' => now()]))->toBeTrue()
        // A local time-of-day, not prose.
        ->and($refused(['local_time' => '9am']))->toBeTrue()
        ->and($refused(['local_time' => '25:00']))->toBeTrue()
        // Monthly operands stay inside a real calendar month.
        ->and($refused(['day_of_month' => 0]))->toBeTrue()
        ->and($refused(['day_of_month' => 32]))->toBeTrue()
        // A window that ends before it starts is not a window.
        ->and($refused(['starts_on' => '2026-12-01', 'ends_on' => '2026-11-01']))->toBeTrue()
        // And the coherent shapes are accepted.
        ->and($refused(['status' => ReminderScheduleStatus::Terminated->value, 'terminated_at' => now()]))->toBeFalse();
});

it('refuses half an occurrence identity on a reminder row', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('CHECK constraints are asserted on PostgreSQL, the production engine.');
    }

    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();
    $occurrence = Reminder::query()->where('reminder_schedule_id', $schedule->id)->first();

    $refused = function (array $update) use ($occurrence): bool {
        try {
            DB::transaction(fn () => DB::table('reminders')->where('id', $occurrence->id)->update($update));

            return false;
        } catch (QueryException) {
            return true;
        }
    };

    // Neither half of the identity may exist without the other: a schedule
    // occurrence always has a key, and a keyed row always has a schedule.
    expect($refused(['occurrence_key' => null]))->toBeTrue()
        ->and($refused(['reminder_schedule_id' => null]))->toBeTrue()
        ->and($refused(['reminder_schedule_id' => null, 'occurrence_key' => null]))->toBeFalse();
});

it('keeps schedules queryable by the two reads that matter', function () {
    expect(Schema::hasIndex('reminder_schedules', 'reminder_schedules_status_cursor_idx'))->toBeTrue()
        ->and(Schema::hasIndex('reminder_schedules', 'reminder_schedules_user_status_idx'))->toBeTrue();
});
