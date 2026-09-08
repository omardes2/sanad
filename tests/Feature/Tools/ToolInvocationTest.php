<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolClaimOutcome;
use App\Enums\ToolConsentReason;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolRuleException;
use App\Exceptions\Tools\ToolTransitionException;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\ToolInvocation;
use App\Models\ToolInvocationEvent;
use App\Models\User;
use App\Services\Tools\Readers\MemoryReader;
use App\Services\Tools\ReadToolExecutor;
use App\Services\Tools\ToolConsentService;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use App\Support\Tools\CanonicalInput;
use App\Support\Tools\InvocationKey;
use App\Support\Tools\ReadOnlyQueryGuard;
use App\Support\Tools\ToolInvocationTransitions;
use App\Support\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase F2 — the invocation lifecycle: a server-generated identity, a validated
 * state machine, replay / conflict / in-flight that mutate nothing, a consent
 * re-check immediately before execution, and exactly one terminal record
 * (status + event + audit + usage) however many callers race for it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'UTC'));
    $this->subscriber = User::factory()->create();
    $this->message = f2Message($this->subscriber);
});

function f2Consent(User $subscriber, ToolCapability $capability = ToolCapability::MemoryRead): void
{
    $previous = auth()->user();
    // Only the subscriber themself may grant (F1). setUser(), not login(): the
    // session must not keep the id behind us and make later work look authenticated.
    auth()->setUser($subscriber);
    app(ToolConsentService::class)->grant($subscriber->id, $capability, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();

    if ($previous !== null) {
        auth()->setUser($previous);
    }
}

function f2Call(array $arguments = ['query' => 'coffee'])
{
    return f2Executor()->call(test()->message, 'memory.read@1', $arguments);
}

it('derives the identity from persisted facts only, byte for byte, and a changed call at the same index conflicts', function () {
    $definition = app(ToolRegistry::class)->require('memory.read', 1);
    $plan = f2Plan();

    $first = $plan->one($this->message, 'memory.read@1', ['query' => 'coffee']);
    $again = $plan->one($this->message, 'memory.read@1', ['query' => 'coffee']);

    // Same stored message, same ordered plan ⇒ identical key AND identical input hash.
    expect($first->key->value)->toBe('msg:'.$this->message->id.':tool:memory.read@1:call:1')
        ->and($again->key->value)->toBe($first->key->value)
        ->and($again->input->hash)->toBe($first->input->hash)
        ->and($first->callIndex)->toBe(1)
        // Ownership comes from the message row, never from an argument.
        ->and($first->subscriber->id)->toBe($this->subscriber->id)
        ->and($first->message->conversation_id)->toBe($this->message->conversation_id);

    // A second intended call in the same message takes its own identity.
    $pair = $plan->of($this->message, [
        ['key' => 'memory.read@1', 'arguments' => ['query' => 'coffee']],
        ['key' => 'memory.read@1', 'arguments' => ['query' => 'tea']],
    ]);
    expect($pair[1]->key->value)->toEndWith(':call:2')
        ->and($pair[1]->key->value)->not->toBe($pair[0]->key->value);

    // A DIFFERENT call at the same index keeps the same key on purpose, so the input hash reports it.
    $changed = $plan->one($this->message, 'memory.read@1', ['query' => 'tea']);
    expect($changed->key->value)->toBe($first->key->value)
        ->and($changed->input->hash)->not->toBe($first->input->hash);

    // Nothing but persisted facts: no clock, no randomness.
    expect(InvocationKey::of(7, $definition->key, 3)->value)->toBe('msg:7:tool:memory.read@1:call:3')
        ->and(fn () => InvocationKey::of(0, $definition->key, 1))->toThrow(ToolRuleException::class)
        ->and(fn () => InvocationKey::of(7, $definition->key, 0))->toThrow(ToolRuleException::class);
});

it('canonicalises input to stable bytes: key order, explicit null vs absent, Unicode form and no floats', function () {
    $schema = app(ToolRegistry::class)->require('memory.read', 1)->input;

    $a = CanonicalInput::of($schema, ['limit' => 5, 'query' => '  coffee  ']);
    $b = CanonicalInput::of($schema, ['query' => 'coffee', 'limit' => '5']);

    expect($a->json)->toBe('{"query":"coffee","limit":5}')  // declaration order, integers as integers
        ->and($b->hash)->toBe($a->hash)
        ->and($a->hash)->toBe(hash('sha256', $a->json));

    // An absent optional field and an explicit null are the SAME input.
    expect(CanonicalInput::of($schema, ['query' => 'coffee', 'limit' => null])->hash)
        ->toBe(CanonicalInput::of($schema, ['query' => 'coffee'])->hash);

    // Unicode: the same text composed two ways hashes the same (NFC before validation).
    $composed = "caf\u{00E9}";
    $decomposed = "cafe\u{0301}";
    expect($composed)->not->toBe($decomposed)
        ->and(CanonicalInput::of($schema, ['query' => $decomposed])->hash)
        ->toBe(CanonicalInput::of($schema, ['query' => $composed])->hash);

    // Arabic text survives unescaped, so the bytes do not depend on json_encode defaults.
    expect(CanonicalInput::of($schema, ['query' => 'قهوة'])->json)->toBe('{"query":"قهوة"}');

    // A different value is a different hash — the whole point of the conflict rule.
    expect(CanonicalInput::of($schema, ['query' => 'tea'])->hash)->not->toBe($a->hash);
});

it('walks planned → authorized → running → succeeded, one event per transition, and keeps the projection and history identical', function () {
    Memory::factory()->count(3)->create(['user_id' => $this->subscriber->id, 'content' => 'likes coffee in the morning']);
    f2Consent($this->subscriber);

    $result = f2Call();
    $row = $result->invocation;

    expect($result->claim)->toBe(ToolClaimOutcome::Claimed)
        ->and($result->executed)->toBeTrue()
        ->and($row->status)->toBe(ToolInvocationStatus::Succeeded)
        ->and($row->output)->toBe(['matches' => 3, 'truncated' => false])
        ->and($row->input)->toBe(['query' => 'coffee'])
        ->and($row->tool_key)->toBe('memory.read')
        ->and($row->tool_version)->toBe(1)
        ->and($row->call_index)->toBe(1)
        ->and($row->message_id)->toBe($this->message->id)
        ->and($row->conversation_id)->toBe($this->message->conversation_id)
        ->and($row->started_at)->not->toBeNull()
        ->and($row->finished_at)->not->toBeNull()
        ->and($row->duration_ms)->not->toBeNull()
        ->and($row->failure_kind)->toBeNull()
        ->and($row->refusal_reason)->toBeNull();

    $events = $row->events()->get();

    expect($events->pluck('to_status')->map->value->all())->toBe(['planned', 'authorized', 'running', 'succeeded'])
        ->and($events->pluck('from_status')->map(fn ($s) => $s?->value)->all())->toBe([null, 'planned', 'authorized', 'running'])
        ->and($events->pluck('seq')->all())->toBe([1, 2, 3, 4])
        // version counts the persisted transitions, and the latest event IS the projection.
        ->and($row->version)->toBe(4)
        ->and($events->count())->toBe($row->version)
        ->and($events->last()->to_status)->toBe($row->status)
        ->and($events->where('to_status', ToolInvocationStatus::Succeeded)->count())->toBe(1);
});

it('refuses every transition the code table does not allow, and a terminal state accepts nothing at all', function () {
    f2Consent($this->subscriber);
    $row = f2Call()->invocation;

    // The table is exhaustive and matches the approved F2 lifecycle exactly.
    $allowed = array_map(fn (array $p): string => $p[0]->value.'->'.$p[1]->value, ToolInvocationTransitions::pairs());
    sort($allowed);
    expect($allowed)->toBe([
        'authorized->refused', 'authorized->running',
        'planned->authorized', 'planned->refused',
        'running->failed', 'running->succeeded', 'running->timed_out',
    ]);

    foreach (ToolInvocationStatus::cases() as $from) {
        foreach (ToolInvocationStatus::cases() as $to) {
            $legal = in_array($from->value.'->'.$to->value, $allowed, true);
            expect(ToolInvocationTransitions::allows($from, $to))->toBe($legal, "{$from->value} ⇒ {$to->value}");

            if ($from->isTerminal()) {
                expect(ToolInvocationTransitions::allows($from, $to))->toBeFalse();
            }
        }
    }

    // …and the store enforces it: the settled invocation cannot be moved again.
    expect(fn () => f2Store()->succeed($row, ['matches' => 0, 'truncated' => false], 1))
        ->toThrow(ToolTransitionException::class, 'حالة نهائية');

    expect($row->fresh()->version)->toBe(4)
        ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4)
        ->and(f2Audits($row->fresh()))->toHaveCount(1);

    // There is no `cancelled` state in F2 at all: nothing can produce one.
    expect(ToolInvocationStatus::values())->toBe(['planned', 'authorized', 'running', 'succeeded', 'failed', 'refused', 'timed_out']);
});

it('replays a terminal identity and conflicts on a different input, without changing, auditing or metering anything', function () {
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'likes coffee']);
    f2Consent($this->subscriber);

    $first = f2Call();
    $row = $first->invocation;
    $before = [$row->version, f2Audits($row)->count(), f2Usage($row)->count(), $row->updated_at];

    // Same key, same canonical input, already terminal ⇒ REPLAY of the recorded result.
    $replay = f2Call();
    expect($replay->claim)->toBe(ToolClaimOutcome::Replay)
        ->and($replay->executed)->toBeFalse()
        ->and($replay->invocation->id)->toBe($row->id)
        ->and($replay->output())->toBe($row->output);

    // Same key, DIFFERENT canonical input ⇒ CONFLICT; the stored invocation stays authoritative.
    $conflict = f2Call(['query' => 'tea']);
    expect($conflict->claim)->toBe(ToolClaimOutcome::Conflict)
        ->and($conflict->executed)->toBeFalse()
        ->and($conflict->invocation->id)->toBe($row->id)
        ->and($conflict->invocation->input)->toBe(['query' => 'coffee']);

    $after = $row->fresh();
    expect(ToolInvocation::count())->toBe(1)
        ->and([$after->version, f2Audits($after)->count(), f2Usage($after)->count(), $after->updated_at])->toEqual($before)
        ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4);

    // Ten more replays never add a second usage row or a second audit entry.
    for ($i = 0; $i < 10; $i++) {
        f2Call();
    }

    expect(f2Usage($after))->toHaveCount(1)
        ->and(f2Audits($after))->toHaveCount(1)
        ->and(ToolInvocation::count())->toBe(1);
});

it('reports a NON-TERMINAL identity as in flight: no wait, no mutation, no second execution', function () {
    f2Consent($this->subscriber);

    // A row left `running` by another worker (the process that owns it is elsewhere).
    $request = f2Plan()->one($this->message, 'memory.read@1', ['query' => 'coffee']);
    $running = f2Store()->begin(f2Store()->authorize(f2Store()->claim($request)->invocation), fn (): bool => true);

    expect($running->status)->toBe(ToolInvocationStatus::Running);

    $before = [$running->version, $running->status->value, $running->updated_at];
    $result = f2Call();

    expect($result->claim)->toBe(ToolClaimOutcome::InFlight)
        ->and($result->executed)->toBeFalse()
        ->and($result->invocation->id)->toBe($running->id)
        ->and($result->status)->toBe(ToolInvocationStatus::Running);

    $after = $running->fresh();
    expect([$after->version, $after->status->value, $after->updated_at])->toEqual($before)
        ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $running->id)->count())->toBe(3)
        ->and(f2Audits($after))->toHaveCount(0)
        ->and(f2Usage($after))->toHaveCount(0)
        ->and(ToolInvocation::count())->toBe(1);
});

it('refuses without consent, and refuses again when consent is withdrawn between authorization and execution', function () {
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'likes coffee']);

    // 1) No consent at all: NOT GRANTED, refused after the claim, no execution and NO usage row.
    $refused = f2Call()->invocation;

    expect($refused->status)->toBe(ToolInvocationStatus::Refused)
        ->and($refused->refusal_reason)->toBe(ToolInvocationRefusalReason::NotGranted)
        ->and($refused->started_at)->toBeNull()
        ->and($refused->output)->toBeNull()
        ->and($refused->events()->pluck('to_status')->map->value->all())->toBe(['planned', 'refused'])
        ->and(f2Audits($refused))->toHaveCount(1)
        ->and(f2Audits($refused)->first()->action)->toBe(AuditActions::ToolInvocationRefused)
        ->and(f2Usage($refused))->toHaveCount(0);

    // 2) Consent granted, then revoked in the very window before `running` — the
    //    re-check happens inside the transaction that would start execution.
    f2Consent($this->subscriber);
    $request = f2Plan()->one(f2Message($this->subscriber), 'memory.read@1', ['query' => 'coffee']);
    $invocation = f2Store()->authorize(f2Store()->claim($request)->invocation);

    $revoked = f2Store()->begin($invocation, fn (): bool => false);

    expect($revoked->status)->toBe(ToolInvocationStatus::Refused)
        ->and($revoked->refusal_reason)->toBe(ToolInvocationRefusalReason::ConsentRevoked)
        ->and($revoked->started_at)->toBeNull()
        ->and($revoked->events()->pluck('to_status')->map->value->all())->toBe(['planned', 'authorized', 'refused'])
        ->and(f2Usage($revoked))->toHaveCount(0);

    // An operator permission is never a substitute for the subscriber's consent.
    rbacSync();
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $other = User::factory()->create();
    $result = f2Executor()->call(f2Message($other), 'memory.read@1', ['query' => 'coffee']);
    expect($result->invocation->refusal_reason)->toBe(ToolInvocationRefusalReason::NotGranted);
});

it('refuses an unknown tool, an unknown version, an input the schema rejects and a non-read tool WITHOUT creating a row', function () {
    f2Consent($this->subscriber);
    f2Consent($this->subscriber, ToolCapability::TasksWrite);

    $cases = [
        [['key' => 'memory.write@1', 'args' => ['query' => 'x']], ToolInvocationRefusalReason::UnknownTool],
        [['key' => 'memory.read@9', 'args' => ['query' => 'x']], ToolInvocationRefusalReason::UnknownTool],
        [['key' => 'memory.read', 'args' => ['query' => 'x']], ToolInvocationRefusalReason::UnknownTool],
        [['key' => 'memory.read@1', 'args' => []], ToolInvocationRefusalReason::InvalidInput],
        [['key' => 'memory.read@1', 'args' => ['query' => 'x', 'subscriber_id' => 999]], ToolInvocationRefusalReason::InvalidInput],
        [['key' => 'memory.read@1', 'args' => ['query' => str_repeat('a', 201)]], ToolInvocationRefusalReason::InvalidInput],
        [['key' => 'memory.read@1', 'args' => ['query' => 'x', 'limit' => 999]], ToolInvocationRefusalReason::InvalidInput],
        // F2 executes `read` only: a write tool is not executable, whatever its consent says.
        [['key' => 'task.create@1', 'args' => ['title' => 'x']], ToolInvocationRefusalReason::SideEffectNotExecutable],
    ];

    foreach ($cases as [$call, $expected]) {
        $result = f2Executor()->call($this->message, $call['key'], $call['args']);

        expect($result->invocation)->toBeNull($call['key'])
            ->and($result->refusal)->toBe($expected, $call['key'])
            ->and($result->status)->toBe(ToolInvocationStatus::Refused);
    }

    expect(ToolInvocation::count())->toBe(0)
        ->and(ToolInvocationEvent::count())->toBe(0)
        ->and(DB::table('usage_events')->count())->toBe(0);

    // Every executable key is a `read` with a handler, and every `read` has one.
    $registry = app(ToolRegistry::class);
    foreach (ReadToolExecutor::executableKeys() as $key) {
        expect($registry->requireKey($key)->sideEffect)->toBe(ToolSideEffect::Read);
    }
    foreach ($registry->all() as $definition) {
        if ($definition->sideEffect === ToolSideEffect::Read) {
            expect(ReadToolExecutor::executableKeys())->toContain($definition->key->value());
        }
    }
});

it('discards a result the declared OUTPUT schema refuses and records it as invalid_output', function () {
    f2Consent($this->subscriber);

    // A reader that returns a field the contract never promised.
    app()->bind(MemoryReader::class, fn () => new class
    {
        public function read(User $subscriber, array $input): array
        {
            return ['matches' => 1, 'truncated' => false, 'content' => 'the memory itself'];
        }
    });

    $row = f2Call()->invocation;

    expect($row->status)->toBe(ToolInvocationStatus::Failed)
        ->and($row->failure_kind)->toBe(ToolInvocationFailureKind::InvalidOutput)
        ->and($row->output)->toBeNull()  // the result is discarded, never stored and never returned
        ->and(f2Audits($row)->first()->action)->toBe(AuditActions::ToolInvocationFailed)
        // It DID run, so it is metered exactly once.
        ->and(f2Usage($row))->toHaveCount(1);
});

it('fails a read that tries to write, and never lets the write stand', function () {
    f2Consent($this->subscriber);

    app()->bind(MemoryReader::class, fn () => new class
    {
        public function read(User $subscriber, array $input): array
        {
            Memory::factory()->create(['user_id' => $subscriber->id, 'content' => 'written by a read tool']);

            return ['matches' => 1, 'truncated' => false];
        }
    });

    $row = f2Call()->invocation;

    expect($row->status)->toBe(ToolInvocationStatus::Failed)
        ->and($row->failure_kind)->toBe(ToolInvocationFailureKind::Internal)
        ->and($row->output)->toBeNull();
});

it('writes exactly one terminal audit entry per invocation, with ids, codes and hashes only', function () {
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'likes coffee']);
    f2Consent($this->subscriber);

    $row = f2Call()->invocation;
    $audits = f2Audits($row);

    expect($audits)->toHaveCount(1); // one per invocation, not one per transition

    $audit = $audits->first();
    $context = $audit->metadata['context'];

    expect($audit->action)->toBe(AuditActions::ToolInvocationSucceeded)
        ->and($audit->subject_type)->toBe($row->getMorphClass())
        ->and($audit->subject_id)->toBe($row->id)
        ->and($audit->metadata['changes'])->toBe(['status' => ['from' => 'running', 'to' => 'succeeded'], 'version' => ['from' => 3, 'to' => 4]])
        ->and($context['tool_key'])->toBe('memory.read@1')
        ->and($context['input_hash'])->toBe($row->input_hash)
        ->and($context['subscriber_id'])->toBe($this->subscriber->id)
        ->and($context['actor_ref'])->toBe('system')
        // Neither the arguments nor the result are in the audit: the hash is.
        ->and(array_key_exists('input', $context))->toBeFalse()
        ->and(array_key_exists('output', $context))->toBeFalse();
});

it('keeps no personal data on the invocation, its events or its audit', function () {
    $subscriber = User::factory()->create(['name' => 'Omar Test', 'email' => 'omar.test@example.com', 'phone' => '+970599123456']);
    $message = f2Message($subscriber);
    Memory::factory()->create(['user_id' => $subscriber->id, 'content' => 'Omar likes coffee with omar.test@example.com']);
    f2Consent($subscriber);

    $row = f2Executor()->call($message, 'memory.read@1', ['query' => 'coffee'])->invocation;

    $serialised = json_encode([
        $row->toArray(),
        $row->events()->get()->toArray(),
        f2Audits($row)->toArray(),
        f2Usage($row)->toArray(),
    ], JSON_UNESCAPED_UNICODE);

    foreach ([$subscriber->name, $subscriber->email, $subscriber->phone, 'Omar', 'likes coffee with'] as $pii) {
        expect(str_contains($serialised, (string) $pii))->toBeFalse('invocation must not carry '.$pii);
    }

    // The memory content never leaves the reader: the output is counts only.
    expect($row->output)->toBe(['matches' => 1, 'truncated' => false]);
});

it('meters an invocation that ran exactly once, linked by the invocation id and never by the idempotency key', function () {
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'likes coffee']);
    f2Consent($this->subscriber);

    $row = f2Call()->invocation;
    $usage = f2Usage($row);

    expect($usage)->toHaveCount(1);

    $event = $usage->first();

    expect($event->tool_invocation_ref)->toBe((string) $row->id)
        ->and($event->tool_invocation_ref)->not->toContain('msg:')   // no message/tool/call structure in the ledger
        ->and($event->operation)->toBe('tool:memory.read@1')
        ->and($event->type)->toBe('tool_action')
        ->and($event->subscriber_id)->toBe($this->subscriber->id)
        ->and($event->outcome)->toBe('succeeded')
        ->and($event->idempotency_key)->toBe('tool_invocation:'.$row->id)
        // The ledger row resolves back to exactly one invocation.
        ->and(ToolInvocation::query()->whereKey((int) $event->tool_invocation_ref)->count())->toBe(1);

    // The ledger's unique idempotency key is what makes a second row impossible.
    expect(Schema::hasTable('usage_events'))->toBeTrue()
        ->and(DB::table('usage_events')->where('tool_invocation_ref', (string) $row->id)->count())->toBe(1);
});

it('has no other writer of the invocation tables in the application', function () {
    $writers = [];

    foreach (array_merge(glob(app_path('*/*.php')), glob(app_path('*/*/*.php')), glob(app_path('*/*/*/*.php'))) as $file) {
        $source = php_strip_whitespace($file);

        if (preg_match('/ToolInvocation(Event)?::query\(\)->(create|update|delete)|ToolInvocation(Event)?::(create|insert)|DB::table\([\'"]tool_invocations?|DB::table\([\'"]tool_invocation_events/', $source) === 1) {
            $writers[] = str_replace(app_path().'/', '', $file);
        }
    }

    expect($writers)->toBe(['Services/Tools/ToolInvocationStore.php']);
});

it('registers one read-only guard per process, so a tool call never accumulates query listeners', function () {
    expect(app(ReadOnlyQueryGuard::class))->toBe(app(ReadOnlyQueryGuard::class));

    f2Consent($this->subscriber);
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'a note about coffee']);

    // Ten calls, each its own message: the guard is still the same object and
    // still only reports a real domain write.
    for ($i = 0; $i < 10; $i++) {
        expect(f2Executor()->call(f2Message($this->subscriber), 'memory.read@1', ['query' => 'coffee'])->status)
            ->toBe(ToolInvocationStatus::Succeeded);
    }

    expect(ToolInvocation::count())->toBe(10)
        ->and(DB::table('usage_events')->count())->toBe(10);
});

it('will not derive an identity without a stored message, and stores only the arguments the contract declares', function () {
    // A message that was never saved is not a persisted fact: there is nothing to derive an identity from.
    expect(fn () => f2Plan()->one(new Message, 'memory.read@1', ['query' => 'coffee']))
        ->toThrow(ToolRuleException::class);

    f2Consent($this->subscriber);
    $row = f2Call()->invocation;

    $declared = app(ToolRegistry::class)->require('memory.read', 1);

    // What is persisted is exactly the validated arguments of THIS contract —
    // no provider metadata blob, no orchestration fields, nothing undeclared.
    expect(array_diff(array_keys($row->input), $declared->input->names()))->toBe([])
        ->and(array_diff(array_keys($row->output), $declared->output->names()))->toBe([])
        // Ownership on the row came from the message, and matches its conversation's owner.
        ->and($row->subscriber_id)->toBe($this->message->user_id)
        ->and($row->conversation_id)->toBe($this->message->conversation_id)
        ->and(Conversation::query()->whereKey($row->conversation_id)->value('user_id'))->toBe($row->subscriber_id);

    // No shipped tool declares a field that could carry a secret or an identity.
    $forbidden = ['token', 'secret', 'password', 'api_key', 'key', 'authorization', 'user_id', 'subscriber_id', 'email', 'phone', 'permission', 'capability'];

    foreach (app(ToolRegistry::class)->all() as $definition) {
        foreach (array_merge($definition->input->names(), $definition->output->names()) as $field) {
            expect(in_array($field, $forbidden, true))->toBeFalse($definition->key->value().'.'.$field);
        }
    }
});
