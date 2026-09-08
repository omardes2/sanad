<?php

declare(strict_types=1);

use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Enums\ToolCapability;
use App\Enums\ToolClaimOutcome;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Support\Audit\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase F3-V1 — the four minimal write tools the V1 launch scope names, each a
 * LOCAL reversible change through a domain service, each exactly once.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'UTC'));
    [$this->subscriber, $this->message] = f3Subject();
});

function f3Call(string $key, array $arguments, ?User $subscriber = null)
{
    return f3Executor()->call(f2Message($subscriber ?? test()->subscriber), $key, $arguments);
}

it('creates a task through the domain service, with ownership and provenance from the message', function () {
    $result = f3Executor()->call($this->message, 'task.create@1', ['title' => 'أجهّز العرض', 'due_on' => '2026-09-11', 'priority' => 'high']);
    $row = $result->invocation;

    expect($result->executed)->toBeTrue()
        ->and($row->status)->toBe(ToolInvocationStatus::Succeeded);

    $task = Task::query()->sole();

    expect($row->output)->toBe(['task_id' => $task->id])
        ->and($task->user_id)->toBe($this->subscriber->id)       // from the message, never the payload
        ->and($task->source_message_id)->toBe($this->message->id)
        ->and($task->title)->toBe('أجهّز العرض')
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->due_at->toDateString())->toBe('2026-09-11')
        // One invocation, one audit, one ledger row — the F2 contract, unchanged.
        ->and($row->events()->pluck('to_status')->map->value->all())->toBe(['planned', 'authorized', 'running', 'succeeded'])
        ->and(f2Audits($row))->toHaveCount(1)
        ->and(f2Audits($row)->first()->action)->toBe(AuditActions::ToolInvocationSucceeded)
        ->and(f2Usage($row))->toHaveCount(1);
});

it('completes a task, and completing it again is idempotent success with the ORIGINAL completed_at', function () {
    $task = Task::factory()->create(['user_id' => $this->subscriber->id, 'status' => TaskStatus::Pending]);

    $first = f3Call('task.complete@1', ['task_id' => $task->id]);
    $completedAt = $task->fresh()->completed_at;

    expect($first->invocation->status)->toBe(ToolInvocationStatus::Succeeded)
        ->and($task->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($completedAt)->not->toBeNull();

    // Saying "done" twice is not an error, and performs no second mutation.
    $this->travelTo(CarbonImmutable::now('UTC')->addHour());
    $second = f3Call('task.complete@1', ['task_id' => $task->id]);

    expect($second->invocation->status)->toBe(ToolInvocationStatus::Succeeded)
        ->and($second->invocation->output['task_id'])->toBe($task->id)
        ->and($task->fresh()->completed_at->equalTo($completedAt))->toBeTrue()
        ->and($second->invocation->id)->not->toBe($first->invocation->id);   // a new slot, same outcome

    // A cancelled task was never completed, and is not rewritten to say it was.
    $cancelled = Task::factory()->create(['user_id' => $this->subscriber->id, 'status' => TaskStatus::Cancelled]);
    $refused = f3Call('task.complete@1', ['task_id' => $cancelled->id]);

    expect($refused->invocation->status)->toBe(ToolInvocationStatus::Failed)
        ->and($refused->invocation->failure_kind)->toBe(ToolInvocationFailureKind::Rule)
        ->and($cancelled->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($cancelled->fresh()->completed_at)->toBeNull();
});

it('schedules a one-time reminder in UTC, with the timezone and channel taken from server context', function () {
    $result = f3Executor()->call($this->message, 'reminder.create@2', ['title' => 'أدفع الإيجار', 'remind_at' => '2026-09-09T09:00']);
    $reminder = Reminder::query()->sole();

    expect($result->invocation->status)->toBe(ToolInvocationStatus::Succeeded)
        ->and($result->invocation->output)->toBe(['reminder_id' => $reminder->id, 'scheduled_for' => '2026-09-09T09:00'])
        ->and($reminder->user_id)->toBe($this->subscriber->id)
        ->and($reminder->remind_at->utc()->format('Y-m-d\TH:i'))->toBe('2026-09-09T09:00')
        // Neither of these is a tool argument — no schema field even declares them.
        ->and($reminder->timezone)->toBe('Asia/Hebron')
        ->and($reminder->channel->value)->toBe($this->message->conversation->channelAccount->channel->value)
        ->and($reminder->status)->toBe(ReminderStatus::Pending)
        ->and($reminder->source_message_id)->toBe($this->message->id);

    // The past is not schedulable.
    $past = f3Call('reminder.create@2', ['title' => 'متأخر', 'remind_at' => '2026-09-07T09:00']);

    expect($past->invocation->status)->toBe(ToolInvocationStatus::Failed)
        ->and($past->invocation->failure_kind)->toBe(ToolInvocationFailureKind::Rule)
        ->and(Reminder::count())->toBe(1);
});

it('cancels only a reminder that has not gone out, and never rewrites a delivery that was attempted', function () {
    $states = [
        'pending' => [ReminderStatus::Pending, ToolInvocationStatus::Succeeded, ReminderStatus::Cancelled],
        'cancelled' => [ReminderStatus::Cancelled, ToolInvocationStatus::Succeeded, ReminderStatus::Cancelled],
        'processing' => [ReminderStatus::Processing, ToolInvocationStatus::Failed, ReminderStatus::Processing],
        'sent' => [ReminderStatus::Sent, ToolInvocationStatus::Failed, ReminderStatus::Sent],
        'failed' => [ReminderStatus::Failed, ToolInvocationStatus::Failed, ReminderStatus::Failed],
    ];

    foreach ($states as $label => [$from, $expected, $after]) {
        $reminder = Reminder::factory()->create(['user_id' => $this->subscriber->id, 'status' => $from]);
        $result = f3Call('reminder.cancel@1', ['reminder_id' => $reminder->id]);

        expect($result->invocation->status)->toBe($expected, $label)
            ->and($reminder->fresh()->status)->toBe($after, $label);

        if ($expected === ToolInvocationStatus::Failed) {
            expect($result->invocation->failure_kind)->toBe(ToolInvocationFailureKind::Rule, $label);
        }
    }
});

it('never reaches another subscriber row, and cannot be used to discover that one exists', function () {
    $other = User::factory()->create();
    $theirTask = Task::factory()->create(['user_id' => $other->id, 'status' => TaskStatus::Pending]);
    $theirReminder = Reminder::factory()->create(['user_id' => $other->id, 'status' => ReminderStatus::Pending]);
    $before = f3DomainSnapshot($other);

    $cases = [
        "another subscriber's task" => ['task.complete@1', ['task_id' => $theirTask->id]],
        'a task id that does not exist' => ['task.complete@1', ['task_id' => 987654]],
        "another subscriber's reminder" => ['reminder.cancel@1', ['reminder_id' => $theirReminder->id]],
        'a reminder id that does not exist' => ['reminder.cancel@1', ['reminder_id' => 987654]],
    ];

    foreach ($cases as $label => [$key, $arguments]) {
        $result = f3Call($key, $arguments);

        // Identical answer either way: the tool cannot tell "not yours" from "not there".
        expect($result->invocation->status)->toBe(ToolInvocationStatus::Failed, $label)
            ->and($result->invocation->failure_kind)->toBe(ToolInvocationFailureKind::NotFound, $label);
    }

    expect(f3DomainSnapshot($other))->toBe($before)
        ->and($theirTask->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($theirReminder->fresh()->status)->toBe(ReminderStatus::Pending);

    // And no schema field exists through which ownership could be named at all.
    foreach (['user_id', 'subscriber_id', 'owner', 'timezone', 'channel', 'permission', 'capability'] as $field) {
        $smuggled = f3Call('task.create@1', ['title' => 'x', $field => $other->id]);

        expect($smuggled->invocation)->toBeNull($field)
            ->and($smuggled->refusal)->toBe(ToolInvocationRefusalReason::InvalidInput, $field);
    }
});

it('refuses a write without consent, and writes nothing', function () {
    $stranger = User::factory()->create();   // consented to nothing
    $message = f2Message($stranger);

    $result = f3Executor()->call($message, 'task.create@1', ['title' => 'مهمة بلا موافقة']);

    expect($result->invocation->status)->toBe(ToolInvocationStatus::Refused)
        ->and($result->invocation->refusal_reason)->toBe(ToolInvocationRefusalReason::NotGranted)
        ->and($result->invocation->started_at)->toBeNull()
        ->and(Task::query()->where('user_id', $stranger->id)->count())->toBe(0)
        ->and(f2Usage($result->invocation))->toHaveCount(0);

    // Consent for the OTHER capability is not consent for this one.
    f3Consent($stranger, ToolCapability::RemindersWrite);
    $still = f3Executor()->call(f2Message($stranger), 'task.create@1', ['title' => 'ما زال بلا موافقة']);

    expect($still->invocation->refusal_reason)->toBe(ToolInvocationRefusalReason::NotGranted)
        ->and(Task::query()->where('user_id', $stranger->id)->count())->toBe(0);
});

it('replays a settled write instead of performing it twice', function () {
    $result = f3Executor()->call($this->message, 'task.create@1', ['title' => 'مرة واحدة']);
    $row = $result->invocation;

    expect(Task::count())->toBe(1);

    // The same slot, the same tool, the same input: the recorded result, no second task.
    for ($i = 0; $i < 5; $i++) {
        $replay = f3Executor()->call($this->message, 'task.create@1', ['title' => 'مرة واحدة']);

        expect($replay->claim)->toBe(ToolClaimOutcome::Replay)
            ->and($replay->executed)->toBeFalse()
            ->and($replay->output())->toBe($row->output);
    }

    expect(Task::count())->toBe(1)
        ->and(ToolInvocation::count())->toBe(1)
        ->and(f2Audits($row->fresh()))->toHaveCount(1)
        ->and(f2Usage($row->fresh()))->toHaveCount(1);

    // A different title at the same slot conflicts — and creates no task.
    $conflict = f3Executor()->call($this->message, 'task.create@1', ['title' => 'عنوان آخر']);

    expect($conflict->claim)->toBe(ToolClaimOutcome::Conflict)
        ->and(Task::count())->toBe(1)
        ->and(Task::query()->sole()->title)->toBe('مرة واحدة');
});
