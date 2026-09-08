<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The read behind `task.list@1` — "what do I have today?", answered from the
 * subscriber's own tasks and nothing else (Phase F4).
 *
 * OWNERSHIP IS NOT AN ARGUMENT. The subscriber comes from the invocation's
 * trusted context; the tool's closed schema declares only `scope` and `limit`,
 * so there is no field through which a model could name another subscriber.
 *
 * The answer is bounded and deterministic: at most `limit` rows (capped by the
 * contract), ordered by due date then id, with the title truncated to the
 * declared length. There is no free-form filter language — `scope` is a closed
 * enum, so the model cannot express a query the server did not anticipate.
 */
final class TaskReader
{
    public const MAX_LIMIT = 20;

    public const DEFAULT_LIMIT = 10;

    /** The declared bound on a title in the output; longer ones are cut, never dropped. */
    public const TITLE = 60;

    /**
     * @param  array{scope?: string, limit?: int}  $input  already validated tool arguments
     * @return array{tasks: list<array{task_id: int, title: string, status: string, due_on: string}>, total: int, truncated: bool}
     */
    public function read(User $subscriber, array $input): array
    {
        $limit = max(1, min((int) ($input['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $scope = $input['scope'] ?? 'open';
        $today = CarbonImmutable::now('UTC')->endOfDay();

        $query = Task::query()->where('user_id', $subscriber->getKey());

        // A closed set of scopes — never a filter the model composes itself.
        match ($scope) {
            'today' => $query->whereIn('status', [TaskStatus::Pending->value, TaskStatus::InProgress->value])
                ->whereNotNull('due_at')->where('due_at', '<=', $today),
            'overdue' => $query->whereIn('status', [TaskStatus::Pending->value, TaskStatus::InProgress->value])
                ->whereNotNull('due_at')->where('due_at', '<', CarbonImmutable::now('UTC')->startOfDay()),
            'completed' => $query->where('status', TaskStatus::Completed->value),
            default => $query->whereIn('status', [TaskStatus::Pending->value, TaskStatus::InProgress->value]),
        };

        $total = (clone $query)->count();

        // Deterministic: due date first (undated last), then id. Never "recent" or "relevant".
        $rows = $query->orderByRaw('due_at IS NULL, due_at ASC')->orderBy('id')->limit($limit)->get();

        return [
            'tasks' => $rows->map(static fn (Task $task): array => [
                'task_id' => (int) $task->getKey(),
                'title' => mb_substr((string) $task->title, 0, self::TITLE),
                'status' => $task->status->value,
                'due_on' => $task->due_at === null ? '' : CarbonImmutable::instance($task->due_at)->utc()->format('Y-m-d'),
            ])->values()->all(),
            'total' => $total,
            'truncated' => $total > $rows->count(),
        ];
    }
}
