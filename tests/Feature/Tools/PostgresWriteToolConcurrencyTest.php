<?php

declare(strict_types=1);

use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Enums\ToolInvocationStatus;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\ToolInvocation;
use App\Models\ToolInvocationEvent;
use Illuminate\Support\Facades\DB;

/**
 * GENUINE parallel tests for the Phase F3-V1 write tools on PostgreSQL —
 * separate PHP processes, no shared transaction.
 *
 * Two different things are proven apart, because they are different:
 *  - REPLAYING ONE SLOT: many requests for the same invocation identity produce
 *    one invocation and one domain mutation;
 *  - MANY DIFFERENT SLOTS acting on the SAME domain row: each is its own
 *    invocation and each executes, and the row's locking keeps it consistent
 *    and idempotent.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Real concurrency test requires the pgsql connection.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('PostgreSQL is not reachable.');
    }
});

it('of 6 concurrent claims of ONE slot creates exactly one task', function () {
    [$subscriber, $message] = f3Subject();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = f2Run(['write', (string) $subscriber->id, (string) $message->id, 'task.create@1', 'مهمة واحدة']);
        }

        $outcomes = f2Outcomes($processes);
        $row = ToolInvocation::query()->where('subscriber_id', $subscriber->id)->firstOrFail();

        expect(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'claimed:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'replay:') || str_starts_with($o, 'in_flight:')))->toHaveCount(5)
            ->and(ToolInvocation::query()->where('subscriber_id', $subscriber->id)->count())->toBe(1)
            // ONE domain row for one slot, however many processes asked.
            ->and(Task::query()->where('user_id', $subscriber->id)->count())->toBe(1)
            ->and($row->status)->toBe(ToolInvocationStatus::Succeeded)
            ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4)
            ->and(f2Audits($row))->toHaveCount(1)
            ->and(f2Usage($row))->toHaveCount(1);
    } finally {
        f3Cleanup($subscriber);
    }
});

it('of 6 concurrent cancels of ONE pending reminder leaves one terminal state and identical idempotent answers', function () {
    [$subscriber, $message] = f3Subject();

    try {
        $reminder = Reminder::factory()->create(['user_id' => $subscriber->id, 'status' => ReminderStatus::Pending]);

        // SIX DIFFERENT SLOTS (six stored messages), all cancelling the same
        // reminder: each is its own invocation and each executes, so the domain
        // row's own lock is what has to hold.
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = f2Run(['write', (string) $subscriber->id, (string) f2Message($subscriber)->id, 'reminder.cancel@1', (string) $reminder->id]);
        }

        $outcomes = f2Outcomes($processes);

        expect(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'claimed:succeeded:')))->toHaveCount(6)
            ->and(Reminder::query()->where('user_id', $subscriber->id)->count())->toBe(1)
            ->and($reminder->fresh()->status)->toBe(ReminderStatus::Cancelled)
            ->and(ToolInvocation::query()->where('subscriber_id', $subscriber->id)->count())->toBe(6);

        // Six invocations, six audits, six ledger rows — one each, never two for one.
        foreach (ToolInvocation::query()->where('subscriber_id', $subscriber->id)->get() as $row) {
            expect($row->status)->toBe(ToolInvocationStatus::Succeeded)
                ->and($row->output['reminder_id'])->toBe($reminder->id)
                ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4)
                ->and(f2Audits($row))->toHaveCount(1)
                ->and(f2Usage($row))->toHaveCount(1);
        }
    } finally {
        f3Cleanup($subscriber);
    }
});

it('of 6 concurrent completions of ONE task from six slots leaves a single completed_at', function () {
    [$subscriber, $message] = f3Subject();

    try {
        $task = Task::factory()->create(['user_id' => $subscriber->id, 'status' => TaskStatus::Pending]);

        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = f2Run(['write', (string) $subscriber->id, (string) f2Message($subscriber)->id, 'task.complete@1', (string) $task->id]);
        }

        $outcomes = f2Outcomes($processes);
        $completedAt = $task->fresh()->completed_at;

        expect(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'claimed:succeeded:')))->toHaveCount(6)
            ->and($task->fresh()->status)->toBe(TaskStatus::Completed)
            ->and($completedAt)->not->toBeNull()
            ->and(Task::query()->where('user_id', $subscriber->id)->count())->toBe(1);

        // Every invocation reports the SAME completed_at: the first one won and
        // the other five returned it without a second mutation.
        $reported = ToolInvocation::query()->where('subscriber_id', $subscriber->id)->get()
            ->map(fn (ToolInvocation $row): string => $row->output['completed_at'])->unique()->values();

        expect($reported)->toHaveCount(1)
            ->and($reported->first())->toBe($completedAt->utc()->format('Y-m-d\TH:i'));
    } finally {
        f3Cleanup($subscriber);
    }
});

it('never touches another subscriber row, however many processes try at once', function () {
    [$subscriber, $message] = f3Subject();
    [$victim, $victimMessage] = f3Subject();

    try {
        $theirTask = Task::factory()->create(['user_id' => $victim->id, 'status' => TaskStatus::Pending]);

        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = f2Run(['write', (string) $subscriber->id, (string) f2Message($subscriber)->id, 'task.complete@1', (string) $theirTask->id]);
        }

        $outcomes = f2Outcomes($processes);

        // Every one of them fails as `not_found` — the same answer a missing id gives.
        // `<claim>:<status>:<id>:<failure kind>`
        expect(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'claimed:failed:') && str_ends_with($o, ':not_found')))->toHaveCount(6)
            ->and($theirTask->fresh()->status)->toBe(TaskStatus::Pending)
            ->and($theirTask->fresh()->completed_at)->toBeNull()
            ->and(Task::query()->where('user_id', $subscriber->id)->count())->toBe(0);
    } finally {
        f3Cleanup($subscriber);
        f3Cleanup($victim);
    }
});
