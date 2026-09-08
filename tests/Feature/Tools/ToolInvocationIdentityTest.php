<?php

declare(strict_types=1);

use App\Data\Tools\ToolCallRequest;
use App\Enums\ToolCapability;
use App\Enums\ToolClaimOutcome;
use App\Enums\ToolConsentReason;
use App\Enums\ToolFieldType;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Models\Memory;
use App\Models\Message;
use App\Models\ToolInvocation;
use App\Models\ToolInvocationEvent;
use App\Models\User;
use App\Services\Tools\ToolConsentService;
use App\Support\Tools\CanonicalInput;
use App\Support\Tools\InvocationKey;
use App\Support\Tools\ToolDefinition;
use App\Support\Tools\ToolField;
use App\Support\Tools\ToolInputPersistence;
use App\Support\Tools\ToolKey;
use App\Support\Tools\ToolRegistry;
use App\Support\Tools\ToolSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase F2 — the identity of an invocation is the SERVER-OWNED CALL SLOT
 * (`msg:<message_id>:call:<n>`), never the tool the model proposed for it.
 *
 * If the tool were part of the identity, a re-plan that put `task.create@1`
 * where `memory.read@1` had run would mint a second key and a second invocation
 * for the same logical call. With the slot as the identity, the tool, its
 * version and the canonical input are CLAIM FACTS stored on the slot, and any of
 * the three differing is a CONFLICT — one row, at most one execution.
 */
beforeEach(function () {
    $this->subscriber = User::factory()->create();
    $this->message = f2Message($this->subscriber);
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'a note about coffee']);

    auth()->setUser($this->subscriber);
    app(ToolConsentService::class)->grant($this->subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();
});

/** A claim of ONE slot with an explicitly named tool, version and input. */
function slotClaim(Message $message, User $subscriber, string $name, int $version, array $arguments, int $callIndex = 1)
{
    $registry = app(ToolRegistry::class);

    $definition = $registry->has($name, $version)
        ? $registry->require($name, $version)
        : ToolDefinition::of(
            key: $name, version: $version,
            title: 'عقد اختباري',
            summary: 'نسخة أخرى من العقد نفسه لإثبات أن الهوية هي الخانة لا الأداة.',
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

    return f2Store()->claim(new ToolCallRequest(
        message: $message,
        subscriber: $subscriber,
        definition: $definition,
        callIndex: $callIndex,
        input: CanonicalInput::of($definition->input, $arguments),
        key: InvocationKey::of((int) $message->getKey(), $callIndex),
    ));
}

it('names the slot and not the tool, so the same slot is one identity whatever tool is proposed', function () {
    $key = InvocationKey::of($this->message->id, 1);

    expect($key->value)->toBe('msg:'.$this->message->id.':call:1')
        ->and($key->value)->not->toContain('memory.read')
        ->and($key->value)->not->toContain('@')
        // The same slot of the same message is the same string, whatever is planned into it.
        ->and(InvocationKey::of($this->message->id, 1)->value)->toBe($key->value)
        ->and(InvocationKey::of($this->message->id, 2)->value)->toBe('msg:'.$this->message->id.':call:2')
        ->and(InvocationKey::of($this->message->id + 1, 1)->value)->not->toBe($key->value);
});

it('claims a slot once and CONFLICTS on a different input, a different version and a different tool at that same slot', function () {
    // 1) memory.read@1 {query: A} claims the slot.
    $first = slotClaim($this->message, $this->subscriber, 'memory.read', 1, ['query' => 'A']);
    $row = $first->invocation;

    expect($first->outcome)->toBe(ToolClaimOutcome::Claimed)
        ->and($row->tool_key)->toBe('memory.read')
        ->and($row->tool_version)->toBe(1);

    $facts = [$row->tool_key, $row->tool_version, $row->input_hash, $row->status->value, $row->version];

    // 2) same tool and version, DIFFERENT input ⇒ conflict.
    // 3) same tool, DIFFERENT version, same input ⇒ conflict.
    // 4) a DIFFERENT tool at the same slot ⇒ conflict.
    $conflicts = [
        'different input' => slotClaim($this->message, $this->subscriber, 'memory.read', 1, ['query' => 'B']),
        'different version' => slotClaim($this->message, $this->subscriber, 'memory.read', 2, ['query' => 'A']),
        'different tool' => slotClaim($this->message, $this->subscriber, 'task.create', 1, ['title' => 'A']),
        'different tool and input' => slotClaim($this->message, $this->subscriber, 'reminder.other', 3, ['query' => 'C']),
    ];

    foreach ($conflicts as $label => $claim) {
        expect($claim->outcome)->toBe(ToolClaimOutcome::Conflict, $label)
            ->and($claim->invocation->id)->toBe($row->id, $label);
    }

    // …and the same tool, version and input replays or reports in flight instead.
    $same = slotClaim($this->message, $this->subscriber, 'memory.read', 1, ['query' => 'A']);
    expect($same->outcome)->toBe(ToolClaimOutcome::InFlight)   // still `planned`: nothing executed it
        ->and($same->invocation->id)->toBe($row->id);

    // Exactly ONE invocation for the slot, untouched by any of the refusals.
    $after = $row->fresh();

    expect(ToolInvocation::count())->toBe(1)
        ->and([$after->tool_key, $after->tool_version, $after->input_hash, $after->status->value, $after->version])->toBe($facts)
        ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(1)
        ->and(f2Audits($after))->toHaveCount(0)
        ->and(f2Usage($after))->toHaveCount(0);
});

it('executes a slot at most once even when the tool proposed for it changes', function () {
    // The real path: the executor claims, runs and settles.
    $result = f2Executor()->call($this->message, 'memory.read@1', ['query' => 'coffee']);
    $row = $result->invocation;

    expect($result->executed)->toBeTrue()
        ->and($row->status)->toBe(ToolInvocationStatus::Succeeded);

    // A later plan proposing a different tool at the SAME slot conflicts and runs nothing.
    $other = slotClaim($this->message, $this->subscriber, 'memory.read', 2, ['query' => 'coffee']);

    expect($other->outcome)->toBe(ToolClaimOutcome::Conflict)
        ->and($other->invocation->id)->toBe($row->id);

    // A write tool at that slot never even reaches a claim: F2 executes reads only.
    $write = f2Executor()->call($this->message, 'task.create@1', ['title' => 'x']);

    expect($write->invocation)->toBeNull()
        ->and($write->refusal?->value)->toBe('side_effect_not_executable');

    expect(ToolInvocation::count())->toBe(1)
        ->and($row->fresh()->version)->toBe(4)
        ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4)
        ->and(f2Audits($row->fresh()))->toHaveCount(1)
        ->and(f2Usage($row->fresh()))->toHaveCount(1);
});

it('makes the call slot a DATABASE integrity rule, not only a service rule', function () {
    $row = f2Executor()->call($this->message, 'memory.read@1', ['query' => 'coffee'])->invocation;

    $base = [
        'subscriber_id' => $this->subscriber->id, 'tool_key' => 'memory.read', 'tool_version' => 1,
        'capability' => 'memory.read', 'side_effect' => 'read', 'input_hash' => str_repeat('b', 64),
        'input' => '{}', 'input_fields' => '[]', 'status' => 'planned', 'call_index' => $row->call_index,
        'message_id' => $row->message_id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    // A DIFFERENT identity string cannot smuggle a second row into the same slot.
    expect(fn () => DB::transaction(fn () => DB::table('tool_invocations')->insert(
        array_merge($base, ['idempotency_key' => 'some:other:key'])
    )))->toThrow(UniqueConstraintViolationException::class);

    // …and neither can the same identity string.
    expect(fn () => DB::transaction(fn () => DB::table('tool_invocations')->insert(
        array_merge($base, ['idempotency_key' => $row->idempotency_key, 'call_index' => 9])
    )))->toThrow(UniqueConstraintViolationException::class);

    // The next slot of the same message is free.
    DB::table('tool_invocations')->insert(array_merge($base, ['idempotency_key' => 'msg:'.$row->message_id.':call:2', 'call_index' => 2]));

    expect(ToolInvocation::query()->where('message_id', $row->message_id)->count())->toBe(2);
});

it('declares per field, in code, which tool arguments may be stored — and every shipped field is sensitive', function () {
    $registry = app(ToolRegistry::class);

    foreach ($registry->all() as $definition) {
        expect(ToolInputPersistence::persistable($definition->key))->toBe([], $definition->key->value());

        foreach ($definition->input->names() as $field) {
            expect(ToolInputPersistence::isSensitive($definition->key, $field))->toBeTrue($definition->key->value().'.'.$field);
        }
    }

    $memory = $registry->require('memory.read', 1)->key;

    // `query` above all: bounded, validated — and still the subscriber's own words.
    expect(ToolInputPersistence::isSensitive($memory, 'query'))->toBeTrue()
        ->and(ToolInputPersistence::filter($memory, ['query' => 'a private sentence', 'limit' => 5]))->toBe([])
        // Structural metadata only: WHICH fields, never what they said.
        ->and(ToolInputPersistence::fields(['query' => 'a private sentence', 'limit' => 5]))->toBe(['limit', 'query'])
        // Fails closed for a tool nobody listed.
        ->and(ToolInputPersistence::persistable(ToolKey::of('unlisted.tool', 1)))->toBe([])
        ->and(ToolInputPersistence::isSensitive(ToolKey::of('unlisted.tool', 1), 'anything'))->toBeTrue();
});
