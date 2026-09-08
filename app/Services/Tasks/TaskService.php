<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Exceptions\Tools\ToolDomainException;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The ONLY writer of `tasks` (Phase F3-V1).
 *
 * OWNERSHIP IS NEVER AN ARGUMENT. Every method takes the subscriber from the
 * caller's trusted context — for a tool call that is the owner of the stored
 * message the invocation was derived from — and every lookup is scoped to it.
 * A task id that belongs to somebody else and a task id that does not exist
 * give the SAME answer, so the tool layer cannot be used to discover what
 * another subscriber owns.
 *
 * Every method here is called INSIDE the invocation's settlement transaction,
 * so a refusal or a crash rolls the mutation back together with the terminal
 * transition: the task and the `succeeded` invocation exist together or not at
 * all.
 */
final class TaskService
{
    /**
     * @param  array{title: string, due_on?: string, priority?: string}  $input  validated tool arguments
     * @return array{task_id: int}
     */
    public function create(User $subscriber, array $input, ?int $sourceMessageId = null): array
    {
        $attributes = [
            'user_id' => $subscriber->getKey(),   // from context, never from the payload
            'title' => $input['title'],
            'status' => TaskStatus::Pending->value,
            'due_at' => isset($input['due_on']) ? CarbonImmutable::parse($input['due_on'], 'UTC')->startOfDay() : null,
            'source_message_id' => $sourceMessageId,
        ];

        // An optional field that was not sent keeps the column's own default;
        // it is never written as NULL over one.
        if (isset($input['priority'])) {
            $attributes['priority'] = $input['priority'];
        }

        $task = Task::query()->create($attributes);

        return ['task_id' => (int) $task->getKey()];
    }

    /**
     * Mark the subscriber's own task complete.
     *
     * Saying "done" twice is not an error: a task already completed returns its
     * EXISTING `completed_at` and performs no second mutation. A cancelled task
     * is refused — it was not completed, and history is not rewritten to say it
     * was.
     *
     * @return array{task_id: int, completed_at: string}
     *
     * @throws ToolDomainException
     */
    public function complete(User $subscriber, int $taskId): array
    {
        $task = Task::query()
            ->where('user_id', $subscriber->getKey())   // the only scope there is
            ->whereKey($taskId)
            ->lockForUpdate()
            ->first();

        if ($task === null) {
            throw ToolDomainException::notFound('المهمة');
        }

        if ($task->status === TaskStatus::Completed) {
            return self::result($task->getKey(), CarbonImmutable::instance($task->completed_at)->utc());
        }

        if ($task->status === TaskStatus::Cancelled) {
            throw ToolDomainException::rule('المهمة ملغاة، ولا تُعتبر منجزة.');
        }

        $now = CarbonImmutable::now('UTC');
        $task->forceFill(['status' => TaskStatus::Completed->value, 'completed_at' => $now])->save();

        return self::result($task->getKey(), $now);
    }

    /** @return array{task_id: int, completed_at: string} */
    private static function result(mixed $taskId, CarbonImmutable $at): array
    {
        // The declared output shape of `task.complete@1`: ids and a UTC instant,
        // never the title and never anything the subscriber wrote.
        return ['task_id' => (int) $taskId, 'completed_at' => $at->format('Y-m-d\TH:i')];
    }
}
