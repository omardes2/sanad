<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Tools\ToolCallRequest;
use App\Enums\ToolCapability;
use App\Enums\ToolFieldType;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolTransitionException;
use App\Models\Message;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Services\Tools\ReadToolExecutor;
use App\Services\Tools\StaleInvocationSweeper;
use App\Services\Tools\ToolConsentService;
use App\Services\Tools\ToolExecutor;
use App\Services\Tools\ToolInvocationStore;
use App\Support\Tools\CanonicalInput;
use App\Support\Tools\InvocationKey;
use App\Support\Tools\ToolCallPlan;
use App\Support\Tools\ToolDefinition;
use App\Support\Tools\ToolField;
use App\Support\Tools\ToolRegistry;
use App\Support\Tools\ToolSchema;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Testing-only probe (Phase F2): ONE invocation step per process, one
 * machine-readable line, so the PostgreSQL races are run by genuinely separate
 * processes with no shared transaction.
 *
 *  execute <subscriber_id> <message_id> <query> [limit]
 *      → <claim>:<status>:<id>   (claimed | replay | in_flight | conflict)
 *      → refused:<reason>        (refused before any claim)
 *  claim   <subscriber_id> <message_id> <tool_name> <version> <query>
 *      → <outcome>:<id>          claims ONE slot with a named tool/version, to
 *        prove the slot identity holds when the proposed tool changes.
 *  start   <subscriber_id> <message_id> <query>   → running:<id>  (claims and begins, executes nothing)
 *  write   <subscriber_id> <message_id> <tool_key> <arg>
 *      → <claim>:<status>:<id> | refused:<reason>   one write tool call
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
            'claim' => $this->claim($store, $registry, $a),
            'write' => $this->write($a),
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

    /**
     * One claim of the slot `msg:<message_id>:call:1` with an explicitly named
     * tool and version. A version the registry does not ship is built here with
     * the same contract, so a genuine "different tool at the same slot" race can
     * be run without shipping a tool the platform does not have.
     *
     * @param  list<string>  $a
     */
    private function claim(ToolInvocationStore $store, ToolRegistry $registry, array $a): string
    {
        $message = $this->message($a[1]);
        $name = $a[2];
        $version = (int) $a[3];

        $definition = $registry->has($name, $version)
            ? $registry->require($name, $version)
            : ToolDefinition::of(
                key: $name, version: $version,
                title: 'قراءة الذاكرة (نسخة اختبارية)',
                summary: 'نسخة أخرى من العقد نفسه، للتحقق من أن هوية النداء هي الخانة لا الأداة.',
                capability: ToolCapability::MemoryRead,
                sideEffect: ToolSideEffect::Read,
                input: ToolSchema::of([
                    ToolField::of('query', ToolFieldType::String, required: true, max: 200),
                    ToolField::of('limit', ToolFieldType::Integer, required: false, max: 50),
                ]),
                output: ToolSchema::of([
                    ToolField::of('matches', ToolFieldType::Integer, required: true, max: 50),
                    ToolField::of('truncated', ToolFieldType::Boolean, required: true),
                ]),
            );

        $claim = $store->claim(new ToolCallRequest(
            message: $message,
            subscriber: User::query()->findOrFail((int) $a[0]),
            definition: $definition,
            callIndex: 1,
            input: CanonicalInput::of($definition->input, ['query' => $a[4]]),
            key: InvocationKey::of((int) $message->getKey(), 1),
        ));

        return $claim->outcome->value.':'.$claim->invocation->getKey();
    }

    /**
     * One write-tool call through the real routing executor.
     *
     * `task.complete@1` and `reminder.cancel@1` take the domain id as the
     * argument; `task.create@1` and `reminder.create@2` take a title.
     *
     * @param  list<string>  $a
     */
    private function write(array $a): string
    {
        $message = $this->message($a[1]);
        $key = $a[2];

        $arguments = match ($key) {
            'task.complete@1' => ['task_id' => (int) $a[3]],
            'reminder.cancel@1' => ['reminder_id' => (int) $a[3]],
            'reminder.create@2' => ['title' => $a[3], 'remind_at' => CarbonImmutable::now('UTC')->addDay()->format('Y-m-d\\TH:i')],
            default => ['title' => $a[3]],
        };

        $result = app(ToolExecutor::class)->call($message, $key, $arguments);

        if ($result->invocation === null) {
            return 'refused:'.$result->refusal?->value;
        }

        return $result->claim?->value.':'.$result->status->value.':'.$result->invocation->getKey()
            .':'.($result->invocation->failure_kind?->value ?? 'none');
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
