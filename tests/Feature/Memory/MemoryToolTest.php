<?php

declare(strict_types=1);

use App\Enums\MemoryCategory;
use App\Enums\MemoryProvenance;
use App\Enums\MessageDirection;
use App\Enums\ToolCapability;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Models\Memory;
use App\Models\Message;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Services\Memory\MemoryCipher;
use App\Support\Memory\MemoryFingerprint;
use App\Support\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase G — durable personal memory through the tool layer.
 *
 * The thing under test is not "can a row be written". It is that a row is
 * written ONLY when the subscriber asked for it in their own words, that what
 * is written is encrypted, that the same memory saved twice is one memory, and
 * that none of it can be pointed at somebody else.
 */
beforeEach(function () {
    $this->subscriber = User::factory()->create();
    memoryConsent($this->subscriber);
});

// ---------------------------------------------------------------- helpers

/** Grant both memory capabilities, as the subscriber themself (the only actor F1 allows). */
function memoryConsent(User $subscriber): void
{
    f3Consent($subscriber, ToolCapability::MemoryRead);
    f3Consent($subscriber, ToolCapability::MemoryWrite);
}

/** A stored INBOUND message carrying exactly these words. */
function memorySaid(User $subscriber, string $text, MessageDirection $direction = MessageDirection::Inbound): Message
{
    $message = f2Message($subscriber);
    $message->forceFill(['text_content' => $text, 'direction' => $direction->value])->save();

    return $message->refresh();
}

/** «احفظ ...» — an instruction, so the write path is permitted. */
function memoryAsk(User $subscriber, string $what = 'شيء'): Message
{
    return memorySaid($subscriber, "احفظ إني {$what}");
}

function memoryWrite(Message $message, array $args)
{
    return f3Executor()->call($message, 'memory.write@1', $args);
}

function memoryRecall(Message $message, array $args)
{
    return f3Executor()->call($message, 'memory.read@2', $args);
}

function memoryForget(Message $message, array $args)
{
    return f3Executor()->call($message, 'memory.forget@1', $args);
}

/** The readable sentence behind a stored row. */
function memoryPlain(Memory $memory): ?string
{
    return app(MemoryCipher::class)->open((string) $memory->getAttribute('content'));
}

// ------------------------------------------------------- the intent gate

it('refuses to save a fact the subscriber merely stated, and saves the one they asked to keep', function () {
    // The model proposes the SAME call in both cases. Only the subscriber's own
    // words differ, and only the server's reading of them decides.
    $stated = memorySaid($this->subscriber, 'أنا بحب القهوة سادة');
    $refused = memoryWrite($stated, ['content' => 'بحب القهوة سادة', 'category' => 'preference']);

    expect($refused->status)->toBe(ToolInvocationStatus::Refused)
        ->and($refused->invocation->refusal_reason)->toBe(ToolInvocationRefusalReason::ExplicitIntentMissing)
        ->and(Memory::count())->toBe(0)
        // The attempt IS recorded: a model reaching for memory without authority
        // is worth seeing in the audit trail, not silently dropped.
        ->and(ToolInvocation::count())->toBe(1);

    $asked = memorySaid($this->subscriber, 'احفظ إني بحب القهوة سادة');
    $saved = memoryWrite($asked, ['content' => 'بحب القهوة سادة', 'category' => 'preference']);

    expect($saved->status)->toBe(ToolInvocationStatus::Succeeded)
        ->and($saved->output()['created'])->toBeTrue()
        ->and(Memory::count())->toBe(1);

    $memory = Memory::query()->sole();

    expect(memoryPlain($memory))->toBe('بحب القهوة سادة')
        ->and($memory->category)->toBe(MemoryCategory::Preference)
        ->and($memory->provenance)->toBe(MemoryProvenance::Explicit)
        ->and($memory->source_message_id)->toBe($asked->id);
});

it('never treats Sanad OWN words as authority to remember something', function () {
    // The same instruction, but outbound: Sanad said it, not the subscriber.
    $own = memorySaid($this->subscriber, 'احفظ إني بحب القهوة سادة', MessageDirection::Outbound);

    $result = memoryWrite($own, ['content' => 'بحب القهوة سادة', 'category' => 'preference']);

    expect($result->invocation->refusal_reason)->toBe(ToolInvocationRefusalReason::ExplicitIntentMissing)
        ->and(Memory::count())->toBe(0);
});

it('does not accept a reminder request as a memory instruction', function () {
    foreach (['ذكرني بكرا الساعة 9 أتصل على البنك', 'تذكرني بكرا الساعة 9'] as $text) {
        $result = memoryWrite(memorySaid($this->subscriber, $text), ['content' => 'أتصل على البنك', 'category' => 'fact']);

        expect($result->invocation->refusal_reason)->toBe(ToolInvocationRefusalReason::ExplicitIntentMissing, $text);
    }

    expect(Memory::count())->toBe(0);
});

// --------------------------------------------------- duplicates and updates

it('treats the same memory saved twice as ONE memory, and refreshes it instead of adding a second', function () {
    $first = memoryWrite(memoryAsk($this->subscriber), ['content' => 'بفضّل التذكير مساءً', 'category' => 'preference', 'importance' => 3]);

    // Same sentence, different tashkeel and spacing: the same memory.
    $again = memoryWrite(memoryAsk($this->subscriber), ['content' => '  بفضل   التذكير مساءً ', 'category' => 'preference', 'importance' => 5]);

    expect($first->output()['created'])->toBeTrue()
        ->and($again->output()['created'])->toBeFalse()
        ->and($again->output()['memory_id'])->toBe($first->output()['memory_id'])
        ->and(Memory::count())->toBe(1)
        ->and(Memory::query()->sole()->importance)->toBe(5);
});

it('keeps genuinely different Arabic words apart, because the normaliser folds no letters', function () {
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'عندي سيارة', 'category' => 'fact']);
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'عندي سياره', 'category' => 'fact']);

    // ة and ه are different letters. Folding them would silently merge two
    // memories and lose the second forever.
    expect(Memory::count())->toBe(2);
});

it('files the same sentence in two categories as two memories', function () {
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'الرياضة كل صباح', 'category' => 'habit']);
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'الرياضة كل صباح', 'category' => 'goal']);

    expect(Memory::count())->toBe(2);
});

// ------------------------------------------------------------- encryption

it('stores memory content as ciphertext, never as readable text', function () {
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'بشتغل مهندس برمجيات', 'category' => 'fact']);

    $stored = (string) DB::table('memories')->value('content');

    expect($stored)->not->toContain('مهندس')
        ->and($stored)->toContain('"kid"')
        ->and(app(MemoryCipher::class)->open($stored))->toBe('بشتغل مهندس برمجيات')
        // And the fingerprint is a keyed MAC, not a digest anyone can recompute.
        ->and(DB::table('memories')->value('fingerprint'))->toBe(MemoryFingerprint::of('بشتغل مهندس برمجيات'))
        ->and(DB::table('memories')->value('fingerprint'))->not->toBe(hash('sha256', 'بشتغل مهندس برمجيات'));
});

it('cannot read a memory sealed under a key it does not hold, and refuses to write without one', function () {
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'بحب القراءة', 'category' => 'preference']);

    // A different key: the row is unreadable, and it is SKIPPED rather than
    // failing the whole read.
    config(['memory.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
    app(MemoryCipher::class)->flush();

    expect(memoryRecall(memoryAsk($this->subscriber), ['query' => 'القراءة'])->output()['memories'])->toBe([]);

    // No key at all: memory is unavailable, and that is a refusal, not plaintext.
    config(['memory.key' => null]);
    app(MemoryCipher::class)->flush();

    $result = memoryWrite(memoryAsk($this->subscriber), ['content' => 'شيء آخر', 'category' => 'fact']);

    expect($result->status)->toBe(ToolInvocationStatus::Failed)
        ->and($result->invocation->failure_kind)->toBe(ToolInvocationFailureKind::MemoryUnavailable)
        ->and(Memory::count())->toBe(1);
});

// ------------------------------------------------------- sensitive content

it('refuses a structured identifier whole, and stores no part of it anywhere', function () {
    $cases = [
        'رقم بطاقتي 4111 1111 1111 1111',
        'my password: hunter2',
        'رقم الهوية 401234567',
    ];

    foreach ($cases as $content) {
        $result = memoryWrite(memoryAsk($this->subscriber), ['content' => $content, 'category' => 'fact']);

        expect($result->status)->toBe(ToolInvocationStatus::Failed, $content)
            ->and($result->invocation->failure_kind)->toBe(ToolInvocationFailureKind::SensitiveContent, $content)
            // Not stored, and not echoed onto the invocation either.
            ->and($result->invocation->input)->toBe([])
            ->and($result->invocation->output)->toBeNull();
    }

    expect(Memory::count())->toBe(0);
});

// ------------------------------------------------------------- capacity

it('refuses a new memory at capacity and evicts nothing the subscriber did not forget', function () {
    config(['memory.max_active' => 3]);

    foreach (['واحد', 'اثنين', 'ثلاثة'] as $content) {
        expect(memoryWrite(memoryAsk($this->subscriber), ['content' => $content, 'category' => 'fact'])->status)
            ->toBe(ToolInvocationStatus::Succeeded);
    }

    $before = Memory::query()->orderBy('id')->pluck('id')->all();

    $result = memoryWrite(memoryAsk($this->subscriber), ['content' => 'أربعة', 'category' => 'fact']);

    expect($result->status)->toBe(ToolInvocationStatus::Failed)
        ->and($result->invocation->failure_kind)->toBe(ToolInvocationFailureKind::MemoryCapacityReached)
        // Every existing memory is exactly where it was: nothing was displaced.
        ->and(Memory::query()->active()->orderBy('id')->pluck('id')->all())->toBe($before)
        ->and(Memory::query()->whereNotNull('archived_at')->count())->toBe(0);

    // Refreshing an EXISTING memory is still allowed at capacity — it adds nothing.
    expect(memoryWrite(memoryAsk($this->subscriber), ['content' => 'واحد', 'category' => 'fact'])->output()['created'])->toBeFalse();
});

// -------------------------------------------------------------- forgetting

it('forgets exactly one unambiguous memory, refuses a vague one, and says so when there is none', function () {
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'بشرب قهوة الصباح', 'category' => 'habit']);
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'بشرب شاي المساء', 'category' => 'habit']);

    // Nothing matches.
    $missing = memoryForget(memoryAsk($this->subscriber), ['query' => 'كرة القدم']);
    expect($missing->invocation->failure_kind)->toBe(ToolInvocationFailureKind::NotFound)
        ->and(Memory::query()->active()->count())->toBe(2);

    // Two match: NOTHING is archived. A vague word never sweeps memories away.
    $vague = memoryForget(memoryAsk($this->subscriber), ['query' => 'بشرب']);
    expect($vague->invocation->failure_kind)->toBe(ToolInvocationFailureKind::Ambiguous)
        ->and(Memory::query()->active()->count())->toBe(2);

    // One matches: exactly one is archived, and the row survives.
    $one = memoryForget(memoryAsk($this->subscriber), ['query' => 'قهوة']);
    expect($one->output())->toBe(['forgotten' => 1])
        ->and(Memory::query()->active()->count())->toBe(1)
        ->and(Memory::count())->toBe(2);

    $archived = Memory::query()->whereNotNull('archived_at')->sole();

    expect(memoryPlain($archived))->toBe('بشرب قهوة الصباح')
        // The slot is released, so the same memory can be saved again later.
        ->and($archived->fingerprint)->toBeNull()
        ->and($archived->metadata['archived_reason'])->toBe('subscriber_request');
});

it('lets a forgotten memory be saved again, because archiving released its slot', function () {
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'بحب المشي', 'category' => 'habit']);
    memoryForget(memoryAsk($this->subscriber), ['query' => 'المشي']);

    $again = memoryWrite(memoryAsk($this->subscriber), ['content' => 'بحب المشي', 'category' => 'habit']);

    expect($again->output()['created'])->toBeTrue()
        ->and(Memory::query()->active()->count())->toBe(1)
        ->and(Memory::count())->toBe(2);
});

// ------------------------------------------------------------- recall

it('answers what it remembers with the memories themselves, bounded and ordered', function () {
    foreach ([['قهوة سادة', 5], ['قهوة بحليب', 1], ['قهوة بالصباح', 3]] as [$content, $importance]) {
        memoryWrite(memoryAsk($this->subscriber), ['content' => $content, 'category' => 'preference', 'importance' => $importance]);
    }

    $all = memoryRecall(memoryAsk($this->subscriber), ['query' => 'قهوة'])->output();

    expect(array_column($all['memories'], 'content'))->toBe(['قهوة سادة', 'قهوة بالصباح', 'قهوة بحليب'])
        ->and($all['truncated'])->toBeFalse()
        ->and($all['memories'][0])->toBe(['content' => 'قهوة سادة', 'category' => 'preference', 'importance' => 5]);

    $bounded = memoryRecall(memoryAsk($this->subscriber), ['query' => 'قهوة', 'limit' => 2])->output();

    expect($bounded['memories'])->toHaveCount(2)
        ->and($bounded['truncated'])->toBeTrue();
});

it('keeps the memories out of the invocation row while the model still sees them', function () {
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'بحب القراءة قبل النوم', 'category' => 'preference']);

    $result = memoryRecall(memoryAsk($this->subscriber), ['query' => 'القراءة']);

    // What the model gets: the memory.
    expect($result->output()['memories'][0]['content'])->toBe('بحب القراءة قبل النوم');

    // What the audit trail keeps: the shape, and nothing else.
    $row = $result->invocation->refresh();

    expect($row->output)->toBe(['memories_count' => 1, 'truncated' => false])
        ->and(json_encode($row->output, JSON_UNESCAPED_UNICODE))->not->toContain('القراءة')
        ->and(json_encode(DB::table('tool_invocations')->pluck('output')->all(), JSON_UNESCAPED_UNICODE))->not->toContain('القراءة');
});

it('gives a replay only what was kept, and never resurrects content the policy refused to store', function () {
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'بحب القراءة قبل النوم', 'category' => 'preference']);

    $message = memoryAsk($this->subscriber);
    $first = memoryRecall($message, ['query' => 'القراءة']);

    // The very same slot again: the invocation is a replay, so nothing executes.
    $replay = memoryRecall($message, ['query' => 'القراءة']);

    expect($first->executed)->toBeTrue()
        ->and($replay->executed)->toBeFalse()
        ->and($replay->output())->toBe(['memories_count' => 1, 'truncated' => false])
        ->and(ToolInvocation::where('tool_key', 'memory.read')->count())->toBe(1);
});

// ------------------------------------------------------- isolation & consent

it('can never be pointed at another subscriber, in any of the three tools', function () {
    $other = User::factory()->create();
    memoryConsent($other);
    memoryWrite(memoryAsk($other, 'بسكن في رام الله'), ['content' => 'بسكن في رام الله', 'category' => 'fact']);

    // Identical content for our subscriber, so the two are only told apart by owner.
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'بسكن في نابلس', 'category' => 'fact']);

    $recall = memoryRecall(memoryAsk($this->subscriber), ['query' => 'بسكن'])->output();

    expect(array_column($recall['memories'], 'content'))->toBe(['بسكن في نابلس']);

    // Forgetting cannot reach across either: it is not found, not archived.
    $forget = memoryForget(memoryAsk($this->subscriber), ['query' => 'رام الله']);

    expect($forget->invocation->failure_kind)->toBe(ToolInvocationFailureKind::NotFound)
        ->and(Memory::query()->where('user_id', $other->id)->active()->count())->toBe(1);
});

it('needs write consent to write and read consent to read, and one never implies the other', function () {
    $subscriber = User::factory()->create();
    f3Consent($subscriber, ToolCapability::MemoryRead);   // read only

    $refused = memoryWrite(memoryAsk($subscriber), ['content' => 'شيء', 'category' => 'fact']);

    expect($refused->invocation->refusal_reason)->toBe(ToolInvocationRefusalReason::NotGranted)
        ->and(Memory::count())->toBe(0);

    $writeOnly = User::factory()->create();
    f3Consent($writeOnly, ToolCapability::MemoryWrite);   // write only
    memoryWrite(memoryAsk($writeOnly), ['content' => 'بحب السفر', 'category' => 'preference']);

    expect(Memory::query()->where('user_id', $writeOnly->id)->count())->toBe(1)
        ->and(memoryRecall(memoryAsk($writeOnly), ['query' => 'السفر'])->invocation->refusal_reason)
        ->toBe(ToolInvocationRefusalReason::NotGranted);
});

it('offers no field a model could use to name a memory, a subscriber or a provenance', function () {
    $registry = app(ToolRegistry::class);

    foreach (['memory.read@1', 'memory.read@2', 'memory.write@1', 'memory.forget@1'] as $key) {
        $fields = $registry->requireKey($key)->input->names();

        expect($fields)->not->toContain('memory_id')
            ->and($fields)->not->toContain('user_id')
            ->and($fields)->not->toContain('subscriber_id')
            ->and($fields)->not->toContain('provenance')
            ->and($fields)->not->toContain('archived_at');
    }

    // And a payload that tries anyway is refused before a claim exists.
    $result = memoryWrite(memoryAsk($this->subscriber), ['content' => 'x', 'category' => 'fact', 'user_id' => 999]);

    expect($result->invocation)->toBeNull()
        ->and($result->refusal)->toBe(ToolInvocationRefusalReason::InvalidInput);
});

it('writes only explicit provenance, and V1 has no path that writes anything else', function () {
    memoryWrite(memoryAsk($this->subscriber), ['content' => 'بحب البحر', 'category' => 'preference']);

    expect(Memory::query()->sole()->provenance)->toBe(MemoryProvenance::Explicit)
        ->and(MemoryProvenance::Inferred->writableInV1())->toBeFalse()
        // Nothing in the application writes an inferred memory.
        ->and(shell_exec('grep -rn "MemoryProvenance::Inferred" '.base_path('app').' | grep -v "Enums/MemoryProvenance.php"'))
        ->toBeEmpty();
});
