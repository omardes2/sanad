<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Memory;
use App\Models\Message;
use App\Services\Tools\ToolExecutor;
use Illuminate\Console\Command;

/**
 * Testing-only probe: ONE durable-memory tool call per process, one
 * machine-readable line, so the PostgreSQL races run in genuinely separate
 * processes with no shared transaction.
 *
 *   write  <message_id> <category> <content>  → created:<id> | refreshed:<id>
 *                                               | refused:<reason> | failed:<kind>
 *   forget <message_id> <query>               → forgotten:<n> | failed:<kind>
 *   recall <message_id> <query>               → recalled:<n>
 *   state  <user_id>                          → active:<n>:archived:<n>
 *
 * It goes through the REAL executor, so consent, the explicit-intent gate, the
 * invocation identity and the settlement transaction all apply exactly as they
 * do in production. Nothing here is faked.
 */
class MemoryProbe extends Command
{
    protected $signature = 'sanad:memory-probe {op} {args?*}';

    protected $description = 'Testing only: perform one durable-memory tool call and print the outcome';

    protected $hidden = true;

    public function handle(ToolExecutor $executor): int
    {
        /** @var list<string> $args */
        $args = (array) $this->argument('args');

        return match ((string) $this->argument('op')) {
            'write' => $this->write($executor, (int) ($args[0] ?? 0), (string) ($args[1] ?? 'fact'), (string) ($args[2] ?? '')),
            'forget' => $this->forget($executor, (int) ($args[0] ?? 0), (string) ($args[1] ?? '')),
            'recall' => $this->recall($executor, (int) ($args[0] ?? 0), (string) ($args[1] ?? '')),
            'state' => $this->state((int) ($args[0] ?? 0)),
            default => self::FAILURE,
        };
    }

    private function write(ToolExecutor $executor, int $messageId, string $category, string $content): int
    {
        $message = Message::query()->find($messageId);

        if ($message === null) {
            $this->line('missing');

            return self::SUCCESS;
        }

        $result = $executor->call($message, 'memory.write@1', ['content' => $content, 'category' => $category]);

        $this->line($this->describe($result, static fn (array $output): string => ($output['created'] ? 'created:' : 'refreshed:').$output['memory_id']));

        return self::SUCCESS;
    }

    private function forget(ToolExecutor $executor, int $messageId, string $query): int
    {
        $message = Message::query()->find($messageId);

        if ($message === null) {
            $this->line('missing');

            return self::SUCCESS;
        }

        $result = $executor->call($message, 'memory.forget@1', ['query' => $query]);

        $this->line($this->describe($result, static fn (array $output): string => 'forgotten:'.$output['forgotten']));

        return self::SUCCESS;
    }

    private function recall(ToolExecutor $executor, int $messageId, string $query): int
    {
        $message = Message::query()->find($messageId);

        if ($message === null) {
            $this->line('missing');

            return self::SUCCESS;
        }

        $result = $executor->call($message, 'memory.read@2', ['query' => $query]);

        $this->line($this->describe($result, static fn (array $output): string => 'recalled:'.count($output['memories'] ?? [])));

        return self::SUCCESS;
    }

    private function state(int $userId): int
    {
        $this->line(sprintf(
            'active:%d:archived:%d',
            Memory::query()->where('user_id', $userId)->whereNull('archived_at')->count(),
            Memory::query()->where('user_id', $userId)->whereNotNull('archived_at')->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  callable(array<string, mixed>): string  $ok
     */
    private function describe(mixed $result, callable $ok): string
    {
        if ($result->invocation === null) {
            return 'refused:'.(string) $result->refusal?->value;
        }

        if ($result->succeeded()) {
            return $ok((array) $result->output());
        }

        return $result->invocation->refusal_reason !== null
            ? 'refused:'.$result->invocation->refusal_reason->value
            : 'failed:'.(string) $result->invocation->failure_kind?->value;
    }
}
