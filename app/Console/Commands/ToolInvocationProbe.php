<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Tools\ToolTransitionException;
use App\Models\Message;
use App\Models\ToolInvocation;
use App\Services\Tools\ReadToolExecutor;
use App\Services\Tools\StaleInvocationSweeper;
use App\Services\Tools\ToolConsentService;
use App\Services\Tools\ToolInvocationStore;
use App\Support\Tools\ToolCallPlan;
use App\Support\Tools\ToolRegistry;
use Illuminate\Console\Command;

/**
 * Testing-only probe (Phase F2): ONE invocation step per process, one
 * machine-readable line, so the PostgreSQL races are run by genuinely separate
 * processes with no shared transaction.
 *
 *  execute <subscriber_id> <message_id> <query> [limit]
 *      → <claim>:<status>:<id>   (claimed | replay | in_flight | conflict)
 *      → refused:<reason>        (refused before any claim)
 *  start   <subscriber_id> <message_id> <query>   → running:<id>  (claims and begins, executes nothing)
 *  settle  <invocation_id>                        → ok:succeeded | lost
 *  sweep                                          → swept:<n>     (grace pulled back, testing only)
 *  state   <invocation_id>                        → <status>:<version>
 */
class ToolInvocationProbe extends Command
{
    protected $signature = 'sanad:tool-invocation-probe {op} {args?*}';

    protected $description = 'Testing only: perform one tool-invocation step and print the outcome';

    protected $hidden = true;

    /** Enough negative grace that a just-started read is already sweepable in a test. */
    private const PROBE_GRACE = -3600;

    public function handle(ReadToolExecutor $executor, ToolInvocationStore $store, ToolCallPlan $plan, ToolConsentService $consents, ToolRegistry $registry): int
    {
        /** @var list<string> $a */
        $a = $this->argument('args');

        $line = match ((string) $this->argument('op')) {
            'execute' => $this->runExecute($executor, $a),
            'start' => $this->start($store, $plan, $consents, $a),
            'settle' => $this->settle($store, (int) $a[0]),
            'sweep' => 'swept:'.(new StaleInvocationSweeper($store, $registry, self::PROBE_GRACE))->sweep(),
            'state' => $this->state((int) $a[0]),
            default => throw new \InvalidArgumentException('Unknown op'),
        };

        $this->line($line);

        return self::SUCCESS;
    }

    /** @param list<string> $a */
    private function runExecute(ReadToolExecutor $executor, array $a): string
    {
        $result = $executor->call($this->message($a[1]), 'memory.read@1', self::toolArguments($a));

        if ($result->invocation === null) {
            return 'refused:'.$result->refusal?->value;
        }

        return $result->claim?->value.':'.$result->status->value.':'.$result->invocation->getKey();
    }

    /** @param list<string> $a */
    private function start(ToolInvocationStore $store, ToolCallPlan $plan, ToolConsentService $consents, array $a): string
    {
        $request = $plan->one($this->message($a[1]), 'memory.read@1', self::toolArguments($a));
        $claim = $store->claim($request);
        $subscriberId = (int) $request->subscriber->getKey();
        $capability = $request->definition->capability;

        $invocation = $store->begin(
            $store->authorize($claim->invocation),
            fn (): bool => $consents->granted($subscriberId, $capability),
        );

        return $invocation->status->value.':'.$invocation->getKey();
    }

    private function settle(ToolInvocationStore $store, int $invocationId): string
    {
        $row = ToolInvocation::query()->findOrFail($invocationId);

        try {
            return 'ok:'.$store->succeed($row, ['matches' => 0, 'truncated' => false], 5)->status->value;
        } catch (ToolTransitionException) {
            return 'lost';
        }
    }

    private function state(int $invocationId): string
    {
        $row = ToolInvocation::query()->find($invocationId);

        return $row === null ? 'missing' : $row->status->value.':'.$row->version;
    }

    private function message(string $id): Message
    {
        return Message::query()->findOrFail((int) $id);
    }

    /**
     * @param  list<string>  $a
     * @return array<string, mixed>
     */
    private static function toolArguments(array $a): array
    {
        $arguments = ['query' => $a[2]];

        if (isset($a[3])) {
            $arguments['limit'] = (int) $a[3];
        }

        return $arguments;
    }
}
