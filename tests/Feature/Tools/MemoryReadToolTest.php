<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolInvocationStatus;
use App\Models\Memory;
use App\Models\User;
use App\Services\Memory\MemoryService;
use App\Services\Tools\ToolConsentService;
use App\Support\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase F2 — `memory.read@1` is a REAL read against real rows, not a stub.
 *
 * Ownership never comes from the model: the subscriber is the owner of the
 * stored message the invocation was derived from, and the tool's closed schema
 * declares only `query` and `limit`, so there is no field a model could use to
 * reach another subscriber.
 */
beforeEach(function () {
    $this->subscriber = User::factory()->create();
    $this->message = f2Message($this->subscriber);

    auth()->setUser($this->subscriber);
    app(ToolConsentService::class)->grant($this->subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();
});

function memoryRead(array $arguments)
{
    return f2Executor()->call(test()->message, 'memory.read@1', $arguments);
}

it('reads the subscriber OWN active memories, counts them and says when the bound cut the answer short', function () {
    // Distinct sentences: two memories with the same normalised text in one
    // category ARE one memory, so a fixture that repeated itself could not exist.
    foreach (['يفضّل القهوة صباحًا', 'القهوة بلا سكر', 'القهوة بعد الغداء'] as $note) {
        Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => $note]);
    }

    foreach (['coffee with milk', 'coffee at work'] as $note) {
        Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => $note]);
    }

    Memory::factory()->archived()->create(['user_id' => $this->subscriber->id, 'content' => 'archived coffee note']);

    $other = User::factory()->create();
    foreach (['someone else coffee', 'their second coffee note'] as $note) {
        Memory::factory()->create(['user_id' => $other->id, 'content' => $note]);
    }

    // Case-insensitive substring, active rows only, this subscriber only. Each
    // distinct question is its own stored message, and therefore its own identity.
    expect(memoryRead(['query' => 'coffee'])->output())->toBe(['matches' => 2, 'truncated' => false])
        ->and(f2Executor()->call(f2Message($this->subscriber), 'memory.read@1', ['query' => 'القهوة'])->output())
        ->toBe(['matches' => 3, 'truncated' => false]);

    // The bound is the tool's, not the caller's wish.
    $bounded = f2Executor()->call(f2Message($this->subscriber), 'memory.read@1', ['query' => 'COFFEE', 'limit' => 1]);
    expect($bounded->output())->toBe(['matches' => 1, 'truncated' => true]);
});

it('cannot be pointed at another subscriber: ownership is the message, and there is no field to say otherwise', function () {
    $other = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        Memory::factory()->create(['user_id' => $other->id, 'content' => "the other subscriber secret {$i}"]);
    }

    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'my own secret']);

    // Every shape of "read someone else's memories" is refused by the closed schema.
    foreach (['user_id', 'subscriber_id', 'owner', 'capability', 'permission', 'conversation_id'] as $field) {
        $result = f2Executor()->call(f2Message($this->subscriber), 'memory.read@1', ['query' => 'secret', $field => $other->id]);

        expect($result->invocation)->toBeNull($field)
            ->and($result->refusal?->value)->toBe('invalid_input', $field);
    }

    // And the reader itself only ever sees the subscriber it was handed.
    expect(memoryRead(['query' => 'secret'])->output())->toBe(['matches' => 1, 'truncated' => false])
        ->and(app(MemoryService::class)->count($other, ['query' => 'secret']))->toBe(['matches' => 5, 'truncated' => false]);
});

it('treats the query as a literal substring, never as a pattern', function () {
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'plain note without wildcards']);
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'a 100% literal note']);

    // The match now happens in application memory over decrypted text rather
    // than as SQL, so there is no LIKE pattern to escape at all — but the
    // GUARANTEE the escaping existed for is unchanged and still pinned here:
    // `%` matches the one memory that literally contains it, not everything.
    expect(memoryRead(['query' => '%'])->output())->toBe(['matches' => 1, 'truncated' => false])
        ->and(f2Executor()->call(f2Message($this->subscriber), 'memory.read@1', ['query' => '_'])->output())
        ->toBe(['matches' => 0, 'truncated' => false]);
});

it('is a read: it issues no write, and the invocation is the only thing the call changed', function () {
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'note about coffee']);
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'second note about coffee']);

    $memoriesBefore = DB::table('memories')->get()->toArray();
    $writes = [];

    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1
            && preg_match('/tool_invocations|tool_invocation_events|audit_logs|usage_events/i', $query->sql) !== 1) {
            $writes[] = $query->sql;
        }
    });

    $result = memoryRead(['query' => 'coffee']);

    expect($result->invocation->status)->toBe(ToolInvocationStatus::Succeeded)
        // Infrastructure writes (projection, events, audit, ledger) are expected; DOMAIN writes are not.
        ->and($writes)->toBe([])
        ->and(DB::table('memories')->get()->toArray())->toEqual($memoriesBefore);
});

it('declares a counts-only output, so no memory content can travel through the tool', function () {
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'a very private note about coffee']);

    $output = memoryRead(['query' => 'coffee'])->output();

    // `@1` is FROZEN: it counts, and a frozen version is never widened into
    // returning content. `memory.read@2` is the version that does that.
    expect(array_keys($output))->toBe(['matches', 'truncated'])
        ->and(app(ToolRegistry::class)->require('memory.read', 1)->output->names())->toBe(['matches', 'truncated'])
        ->and(json_encode($output))->not->toContain('private')
        ->and(app(ToolRegistry::class)->require('memory.read', 2)->output->names())->toBe(['memories', 'truncated']);
});
