<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\Tools\ToolCallRequest;
use App\Data\Tools\ToolClaim;
use App\Data\Tools\ToolInvocationResult;
use App\Enums\ChannelType;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolDomainException;
use App\Exceptions\Tools\ToolRuleException;
use App\Models\Conversation;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use App\Services\Tasks\TaskService;
use App\Support\Tools\DomainWriteGuard;
use App\Support\Tools\ToolWriteTargets;
use Throwable;

/**
 * Executes LOCAL, REVERSIBLE WRITE tools, and nothing else (Phase F3-V1).
 *
 * Handlers come from the CODE ALLOWLIST below, keyed by `name@version`. Nothing
 * is resolved from registry metadata, from the database or from a payload, and
 * a handler is always a DOMAIN SERVICE — a tool never touches a table itself.
 *
 * EXACTLY ONCE, and only here. The domain mutation runs inside the invocation's
 * settlement transaction (`ToolInvocationStore::succeedWith`), so the domain row
 * and the `succeeded` invocation commit together or not at all. A crash, a
 * domain refusal, or losing the settlement race to another process leaves the
 * domain table untouched and the invocation `running` for the sweeper. This
 * property exists ONLY because the mutation shares the database with the
 * invocation record; it must never be assumed for an HTTP API, a payment
 * gateway, a WhatsApp send, an email, a phone call or any external service.
 *
 * A domain refusal — the row is not this subscriber's, or its state forbids the
 * change — arrives after `running`, so it is recorded as `failed` with a closed
 * kind (`not_found` / `rule`). `refused` stays reserved for what is decided
 * before execution starts.
 */
final class WriteToolExecutor
{
    /**
     * @var array<string, array{0: class-string, 1: string}>
     */
    private const HANDLERS = [
        'task.create@1' => [TaskService::class, 'create'],
        'task.complete@1' => [TaskService::class, 'complete'],
        'reminder.create@2' => [ReminderService::class, 'create'],
        'reminder.cancel@1' => [ReminderService::class, 'cancel'],
    ];

    public function __construct(
        private readonly ToolInvocationStore $store,
        private readonly ToolConsentService $consents,
        private readonly DomainWriteGuard $guard,
    ) {}

    /** @return list<string> the write tools F3-V1 can actually execute */
    public static function executableKeys(): array
    {
        return array_keys(self::HANDLERS);
    }

    /**
     * The class and approval checks have already been made by `ToolExecutor`,
     * which owns the rejection precedence. What is left is identity, consent
     * and the transactional write.
     */
    public function execute(ToolCallRequest $request): ToolInvocationResult
    {
        if ($request->definition->sideEffect !== ToolSideEffect::Write
            || $request->definition->needsApproval()
            || ! array_key_exists($request->toolKey(), self::HANDLERS)) {
            return ToolInvocationResult::refusedBeforeClaim(ToolInvocationRefusalReason::SideEffectNotExecutable);
        }

        if (! User::query()->whereKey($request->subscriber->getKey())->exists()) {
            return ToolInvocationResult::refusedBeforeClaim(ToolInvocationRefusalReason::SubscriberMissing);
        }

        $claim = $this->store->claim($request);

        // Replay, in flight and conflict changed nothing and execute nothing.
        if (! $claim->isClaimed()) {
            return ToolInvocationResult::settled($claim->outcome, $claim->invocation, executed: false);
        }

        $invocation = $claim->invocation;
        $capability = $request->definition->capability;
        $subscriberId = (int) $request->subscriber->getKey();

        if (! $this->consents->granted($subscriberId, $capability)) {
            return $this->result($claim, $this->store->refuse($invocation, ToolInvocationRefusalReason::NotGranted), executed: false);
        }

        $invocation = $this->store->authorize($invocation);

        // Consent is re-read inside the transaction that writes `running`.
        $invocation = $this->store->begin($invocation, fn (): bool => $this->consents->granted($subscriberId, $capability));

        if ($invocation->status !== ToolInvocationStatus::Running) {
            return $this->result($claim, $invocation, executed: false);
        }

        return $this->result($claim, $this->run($request, $invocation), executed: true);
    }

    // ------------------------------------------------------------------

    private function run(ToolCallRequest $request, ToolInvocation $invocation): ToolInvocation
    {
        $definition = $request->definition;
        $startedAt = hrtime(true);

        try {
            // The domain write and the terminal record commit together.
            return $this->store->succeedWith($invocation, function () use ($request, $definition): array {
                $raw = $this->guard->run(
                    ToolWriteTargets::for($definition->key),
                    fn (): array => $this->call($request),
                );

                // The declared OUTPUT contract is closed: an undeclared or
                // out-of-bound result field rolls the whole write back.
                return $definition->output->validate($raw);
            }, self::elapsed($startedAt));
        } catch (ToolDomainException $e) {
            return $this->store->fail($invocation, $e->kind, self::elapsed($startedAt));
        } catch (ToolRuleException $e) {
            return $this->store->fail(
                $invocation,
                $e->rule === 'write_scope' ? ToolInvocationFailureKind::Internal : ToolInvocationFailureKind::InvalidOutput,
                self::elapsed($startedAt),
            );
        } catch (Throwable) {
            return $this->store->fail($invocation, ToolInvocationFailureKind::ToolError, self::elapsed($startedAt));
        }
    }

    /**
     * Call the domain service. Ownership, the timezone snapshot and the channel
     * all come from the invocation's trusted context — never from the payload,
     * which cannot even declare them.
     *
     * @return array<string, mixed>
     */
    private function call(ToolCallRequest $request): array
    {
        [$class, $method] = self::HANDLERS[$request->toolKey()];
        $service = app($class);
        $subscriber = $request->subscriber;
        $values = $request->input->values;
        $messageId = $request->message->getKey();

        return match ($request->toolKey()) {
            'task.create@1' => $service->{$method}($subscriber, $values, $messageId),
            'task.complete@1' => $service->{$method}($subscriber, (int) $values['task_id']),
            'reminder.create@2' => $service->{$method}($subscriber, $values, $this->channel($request), $messageId),
            'reminder.cancel@1' => $service->{$method}($subscriber, (int) $values['reminder_id']),
        };
    }

    /**
     * The channel the message arrived on, read through the conversation's own
     * channel account — trusted context, never a tool argument, and there is no
     * schema field a model could use to redirect a reminder elsewhere.
     */
    private function channel(ToolCallRequest $request): ChannelType
    {
        $channel = Conversation::query()
            ->whereKey($request->message->conversation_id)
            ->join('channel_accounts', 'channel_accounts.id', '=', 'conversations.channel_account_id')
            ->value('channel_accounts.channel');

        if ($channel === null) {
            throw ToolDomainException::rule('تعذّر تحديد قناة التذكير من سياق المحادثة.');
        }

        return $channel instanceof ChannelType ? $channel : ChannelType::from((string) $channel);
    }

    private function result(ToolClaim $claim, ToolInvocation $invocation, bool $executed): ToolInvocationResult
    {
        return ToolInvocationResult::settled($claim->outcome, $invocation, $executed);
    }

    private static function elapsed(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
