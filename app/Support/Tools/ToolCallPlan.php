<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Data\Tools\ToolCallRequest;
use App\Exceptions\Tools\ToolDefinitionException;
use App\Exceptions\Tools\ToolRuleException;
use App\Models\Message;
use App\Models\User;

/**
 * The deterministic, ordered plan of the tool calls of ONE stored message
 * (Phase F2) — the single production authority that assigns a call index and
 * therefore an invocation identity.
 *
 * The plan is a pure function of (the message row, the ordered list of intended
 * calls): position 1 is always position 1, so re-processing the same message
 * with the same plan derives the same keys byte for byte. There is no provider
 * tool-calling in F2, so the caller is Sanad's own orchestration and the order
 * is whatever it deterministically produced.
 *
 * The identity of a call is its SLOT — `msg:<id>:call:<n>` — and never the tool
 * proposed for it. Which tool and which version were claimed are facts stored on
 * the invocation, so a plan that puts a different tool at an existing slot
 * conflicts with the stored one instead of minting a second identity.
 *
 * Ownership is taken from the message and from nothing else: the subscriber is
 * the message's user and the conversation is the message's conversation, so a
 * payload that names another subscriber, another conversation, a capability or
 * a permission is simply not consulted — those fields do not exist in any tool
 * schema, and `ToolSchema::validate()` would refuse them anyway.
 */
final class ToolCallPlan
{
    public function __construct(private readonly ToolRegistry $registry) {}

    /**
     * @param  list<array{key: string, arguments: array<string, mixed>}>  $calls  in their deterministic order
     * @return list<ToolCallRequest>
     *
     * @throws ToolRuleException|ToolDefinitionException
     */
    public function of(Message $message, array $calls): array
    {
        if ($message->getKey() === null) {
            throw ToolRuleException::of('message', 'الخطة تحتاج رسالة مخزَّنة، لا رسالة غير محفوظة.');
        }

        $subscriber = $message->user()->first();

        if (! $subscriber instanceof User) {
            throw ToolRuleException::of('subscriber', 'الرسالة لا تعود إلى مشترك قائم.');
        }

        $requests = [];
        $index = 0;

        foreach ($calls as $call) {
            $index++;
            $definition = $this->registry->requireKey($call['key']);

            $requests[] = new ToolCallRequest(
                message: $message,
                subscriber: $subscriber,
                definition: $definition,
                callIndex: $index,
                input: CanonicalInput::of($definition->input, $call['arguments']),
                key: InvocationKey::of((int) $message->getKey(), $index),
            );
        }

        return $requests;
    }

    /**
     * ONE call at an explicit slot of this message.
     *
     * The slot is stated by the caller because a turn may execute tools in more
     * than one round: round two continues the numbering of round one, so the two
     * rounds never claim the same slot. The index still comes from the
     * deterministic structure of the turn — the position of the call in the
     * ordered plan — and never from a provider, a clock or a counter of rows.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function one(Message $message, string $key, array $arguments, int $callIndex = 1): ToolCallRequest
    {
        if ($message->getKey() === null) {
            throw ToolRuleException::of('message', 'الخطة تحتاج رسالة مخزَّنة، لا رسالة غير محفوظة.');
        }

        $subscriber = $message->user()->first();

        if (! $subscriber instanceof User) {
            throw ToolRuleException::of('subscriber', 'الرسالة لا تعود إلى مشترك قائم.');
        }

        $definition = $this->registry->requireKey($key);

        return new ToolCallRequest(
            message: $message,
            subscriber: $subscriber,
            definition: $definition,
            callIndex: $callIndex,
            input: CanonicalInput::of($definition->input, $arguments),
            key: InvocationKey::of((int) $message->getKey(), $callIndex),
        );
    }
}
