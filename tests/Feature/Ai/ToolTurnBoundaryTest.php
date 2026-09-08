<?php

declare(strict_types=1);

use App\Agents\AiAgentOrchestrator;
use App\Data\Ai\AiToolCall;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\ToolCapability;
use App\Exceptions\Ai\AiException;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Services\Ai\ToolTurnRunner;
use App\Services\Tools\ToolExecutor;
use App\Support\Tools\ToolCallPlan;
use App\Support\Tools\ToolCatalog;
use App\Support\Tools\ToolRegistry;
use App\Support\Tools\ToolTurnBudget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Phase F4 — the budget, the authority boundary and the failure matrix.
 */
beforeEach(function () {
    aiConfigure();
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'UTC'));
});

it('counts the turn on the server, and nothing a provider returns can raise or reset it', function () {
    $budget = new ToolTurnBudget;

    expect(ToolTurnBudget::MAX_PROVIDER_CALLS)->toBe(3)
        ->and(ToolTurnBudget::MAX_TOOL_ROUNDS)->toBe(2)
        ->and(ToolTurnBudget::MAX_TOOL_INVOCATIONS)->toBe(3);

    // Call 1 may offer tools.
    expect($budget->mayCallProvider())->toBeTrue()
        ->and($budget->mayOfferTools())->toBeTrue()
        ->and($budget->countProviderCall())->toBe(1);

    // A batch runs only if the WHOLE of it fits.
    expect($budget->fits(3))->toBeTrue()
        ->and($budget->fits(4))->toBeFalse()
        ->and($budget->fits(0))->toBeFalse();

    $budget->countRound(2);

    // Call 2 may still offer the second and last round, bounded by what is left.
    expect($budget->toolRounds())->toBe(1)
        ->and($budget->toolInvocations())->toBe(2)
        ->and($budget->remainingInvocations())->toBe(1)
        ->and($budget->countProviderCall())->toBe(2)
        ->and($budget->mayOfferTools())->toBeFalse()   // call 3 is synthesis only
        ->and($budget->fits(2))->toBeFalse()
        ->and($budget->fits(1))->toBeTrue();

    $budget->countRound(1);

    // Nothing is left: two rounds, three invocations, and the third call is the last.
    expect($budget->toolRounds())->toBe(2)
        ->and($budget->toolInvocations())->toBe(3)
        ->and($budget->fits(1))->toBeFalse()
        ->and($budget->mayOfferTools())->toBeFalse()
        ->and($budget->countProviderCall())->toBe(3)
        ->and($budget->mayCallProvider())->toBeFalse();   // there is no fourth call
});

/** A consented subscriber with a stored message. */
function boundarySubject(): array
{
    $user = User::factory()->create(['is_admin' => false, 'timezone' => 'Asia/Hebron']);
    $account = ChannelAccount::factory()->for($user)->create();
    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);
    $message = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => 'افعل شيئًا',
    ]);

    foreach ([ToolCapability::TasksWrite, ToolCapability::RemindersWrite, ToolCapability::MemoryRead] as $capability) {
        f3Consent($user, $capability);
    }

    return [$user, $conversation, $message];
}

it('turns every failure shape into a bounded machine-readable result, and leaks no internals', function () {
    [$user, $conversation, $message] = boundarySubject();
    $other = User::factory()->create();
    $theirTask = Task::factory()->create(['user_id' => $other->id, 'status' => 'pending']);
    $runner = app(ToolTurnRunner::class);
    $budget = new ToolTurnBudget;

    $cases = [
        'unknown tool' => [new AiToolCall('c1', 'nope__v1', []), 'unknown_tool'],
        'unexposed external write' => [new AiToolCall('c2', 'reminder_create__v1', ['title' => 'x', 'remind_at' => '2026-09-09T09:00']), 'unknown_tool'],
        'malformed arguments' => [new AiToolCall('c3', 'task_create__v1', []), 'invalid_input'],
        'argument out of bounds' => [new AiToolCall('c4', 'task_create__v1', ['title' => str_repeat('a', 200)]), 'invalid_input'],
        'undeclared argument' => [new AiToolCall('c5', 'task_create__v1', ['title' => 'x', 'user_id' => $other->id]), 'invalid_input'],
        "another subscriber's row" => [new AiToolCall('c6', 'task_complete__v1', ['task_id' => $theirTask->id]), 'not_found'],
        'a row that does not exist' => [new AiToolCall('c7', 'task_complete__v1', ['task_id' => 987654]), 'not_found'],
    ];

    $slot = 1;

    foreach ($cases as $label => [$call, $expected]) {
        $result = $runner->run($message, [$call], $budget)[0];
        $budget->countRound(1);

        expect($result->success)->toBeFalse($label)
            ->and($result->error)->toBe($expected, $label)
            ->and($result->toolCallId)->toBe($call->id, $label);

        // What goes back to the model is a code and nothing else.
        $payload = json_encode($result->toMessage()->toArray(), JSON_UNESCAPED_UNICODE);

        foreach (['idempotency', 'msg:', 'subscriber_id', 'capability', 'audit', 'Exception', 'invocation_id'] as $leak) {
            expect(str_contains($payload, $leak))->toBeFalse($label.' leaked '.$leak);
        }
    }

    expect($theirTask->fresh()->completed_at)->toBeNull();
});

it('gives the model no way to control identity, consent or execution authority', function () {
    $catalog = app(ToolCatalog::class);
    $forbidden = ['user_id', 'subscriber_id', 'conversation_id', 'owner', 'consent', 'capability', 'permission', 'approval', 'call_index', 'idempotency_key', 'status', 'retries', 'rate_limit', 'timezone', 'channel'];

    foreach ($catalog->expose() as $tool) {
        $properties = $tool->parameters['properties'];
        $declared = is_array($properties) ? array_keys($properties) : [];

        foreach ($forbidden as $field) {
            expect(in_array($field, $declared, true))->toBeFalse($tool->name.' exposes '.$field);
        }
    }

    // And the executor still refuses a non-exposed class if one ever reached it:
    // hiding from the provider is never the only control.
    $registry = app(ToolRegistry::class);
    $frozen = $registry->require('reminder.create', 1);

    expect(ToolCatalog::isExposable($frozen))->toBeFalse()
        ->and(app(ToolExecutor::class)->execute(
            app(ToolCallPlan::class)->one(boundarySubject()[2], 'reminder.create@1', ['title' => 'x', 'remind_at' => '2026-09-09T09:00'])
        )->refusal?->value)->toBe('side_effect_not_executable');

    expect(ToolInvocation::count())->toBe(0);
});

it('does not retry, fail over or loop when the provider fails mid-turn', function () {
    // The `reply` policy answers instead of rethrowing, so the turn's own
    // behaviour is what this test sees rather than the queue's retry.
    aiConfigure(['ai.failure_behavior' => 'reply']);
    [$user, $conversation, $message] = boundarySubject();

    // The provider dies on the SECOND call, after a tool round already ran.
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push([
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
                'id' => 'call_0', 'type' => 'function',
                'function' => ['name' => 'task_create__v1', 'arguments' => json_encode(['title' => 'أ'])],
            ]]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3],
        ])
        ->push(['error' => ['message' => 'server error']], 500),
    ]);

    $reply = app(AiAgentOrchestrator::class)->handle($user, $conversation, $message);

    // The tool DID run once and is settled; the turn then failed cleanly with the
    // configured fallback rather than retrying the write or looping.
    expect(Task::count())->toBe(1)
        ->and(ToolInvocation::query()->sole()->status->value)->toBe('succeeded')
        ->and($reply->metadata['ai']['failed'] ?? false)->toBeTrue();
});

it('lets the queue retry a transient failure without re-executing the write that already ran', function () {
    [$user, $conversation, $message] = boundarySubject();

    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push([
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
                'id' => 'call_0', 'type' => 'function',
                'function' => ['name' => 'task_create__v1', 'arguments' => json_encode(['title' => 'أ'])],
            ]]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3],
        ])
        ->push(['error' => ['message' => 'server error']], 500)
        // The retry: the same proposal at the same slot, then a real answer.
        ->push([
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
                'id' => 'call_0', 'type' => 'function',
                'function' => ['name' => 'task_create__v1', 'arguments' => json_encode(['title' => 'أ'])],
            ]]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3],
        ])
        ->push([
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'تمام.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ]),
    ]);

    // Under the default `retry` policy a transient failure is rethrown, so the
    // queue — not this class — decides to try again.
    expect(fn () => app(AiAgentOrchestrator::class)->handle($user, $conversation, $message))
        ->toThrow(AiException::class);

    expect(Task::count())->toBe(1);

    // The retry re-derives the SAME slot, so the write is replayed, not repeated.
    $reply = app(AiAgentOrchestrator::class)->handle($user, $conversation, $message);

    expect($reply->text)->toBe('تمام.')
        ->and(Task::count())->toBe(1)
        ->and(ToolInvocation::count())->toBe(1);
});
