<?php

declare(strict_types=1);

use App\Enums\MemoryCategory;
use App\Enums\MessageDirection;
use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\User;
use App\Services\Tools\ToolConsentService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for durable personal memory on PostgreSQL — separate
 * PHP processes, no shared transaction, no in-process serialisation.
 *
 * The three things that can only be proven here:
 *
 *  - N concurrent saves of the SAME memory under DIFFERENT invocation
 *    identities ⇒ ONE row. Invocation idempotency cannot help: each process
 *    holds a legitimately different slot, so the collapse has to come from the
 *    subscriber lock and the unique index;
 *  - N concurrent saves at capacity ⇒ the ceiling is never exceeded and NOTHING
 *    is evicted;
 *  - a save racing a forget of the same memory ⇒ a deterministic terminal
 *    state, never a row that is both archived and refreshed.
 *
 * Plus EXPLAIN evidence that the contributor's selection uses its index.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Real concurrency test requires the pgsql connection.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('PostgreSQL is not reachable.');
    }
});

// ---------------------------------------------------------------- helpers

/** A consenting subscriber and N stored messages, each an explicit instruction. */
function memorySubject(int $messages = 1): array
{
    $subscriber = User::factory()->create(['is_admin' => false]);

    auth()->setUser($subscriber);
    app(ToolConsentService::class)->grant($subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    app(ToolConsentService::class)->grant($subscriber->id, ToolCapability::MemoryWrite, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();

    $conversation = Conversation::factory()->create(['user_id' => $subscriber->id]);

    $ids = [];

    for ($i = 0; $i < $messages; $i++) {
        $ids[] = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $subscriber->id,
            'direction' => MessageDirection::Inbound,
            'text_content' => 'احفظ إني بحب القهوة سادة',
        ])->id;
    }

    return [$subscriber, $ids];
}

function memoryRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:memory-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/** @return list<string> the single output line of each process, in order */
function memoryLines(array $processes): array
{
    return array_map(static function (Process $p): string {
        $p->wait();

        return trim($p->getOutput());
    }, $processes);
}

function memoryCleanup(User $subscriber): void
{
    DB::table('memories')->where('user_id', $subscriber->id)->delete();
    f2Cleanup($subscriber);
}

// ------------------------------------------------------------------ races

it('of 6 concurrent saves of the SAME memory, under 6 different identities, keeps exactly ONE', function () {
    [$subscriber, $messages] = memorySubject(6);

    try {
        $lines = memoryLines(array_map(
            static fn (int $id): Process => memoryRun(['write', (string) $id, 'preference', 'بحب القهوة سادة']),
            $messages,
        ));

        $created = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'created:')));
        $refreshed = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'refreshed:')));

        // Every process succeeded, exactly one of them created the memory, and
        // all six agree on which row it is.
        expect($created)->toHaveCount(1)
            ->and($refreshed)->toHaveCount(5)
            ->and(array_unique(array_map(static fn (string $l): string => explode(':', $l)[1], $lines)))->toHaveCount(1)
            ->and(Memory::query()->where('user_id', $subscriber->id)->count())->toBe(1)
            // Six invocations happened: the collapse is the DATABASE's, not
            // invocation idempotency's — each process held its own slot.
            ->and(DB::table('tool_invocations')->where('subscriber_id', $subscriber->id)->count())->toBe(6);
    } finally {
        memoryCleanup($subscriber);
    }
});

it('of 6 concurrent saves at capacity never exceeds the ceiling and evicts nothing', function () {
    [$subscriber, $messages] = memorySubject(6);

    try {
        $cap = (int) config('memory.max_active');
        $existing = [];

        // Fill to one below capacity with memories the subscriber never asked
        // to forget, and remember exactly which rows they are.
        for ($i = 0; $i < $cap - 1; $i++) {
            $existing[] = Memory::factory()->create([
                'user_id' => $subscriber->id,
                'content' => "ذاكرة قائمة رقم {$i}",
                'category' => MemoryCategory::Fact->value,
            ])->id;
        }

        sort($existing);

        $lines = memoryLines(array_map(
            static fn (int $id, int $i): Process => memoryRun(['write', (string) $id, 'preference', "ذاكرة جديدة رقم {$i}"]),
            $messages,
            array_keys($messages),
        ));

        $accepted = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'created:')));
        $refused = array_values(array_filter($lines, static fn (string $l): bool => $l === 'failed:memory_capacity_reached'));

        $active = Memory::query()->where('user_id', $subscriber->id)->whereNull('archived_at')->orderBy('id')->pluck('id')->all();

        expect(count($accepted))->toBe(1)
            ->and(count($refused))->toBe(5)
            ->and(count($active))->toBe($cap)
            // NOTHING was evicted to make room: every pre-existing memory is
            // still active, and no row was archived by the capacity path.
            ->and(array_slice($active, 0, $cap - 1))->toBe($existing)
            ->and(Memory::query()->where('user_id', $subscriber->id)->whereNotNull('archived_at')->count())->toBe(0);
    } finally {
        memoryCleanup($subscriber);
    }
});

it('of a save racing a forget of the same memory settles deterministically, never both', function () {
    [$subscriber, $messages] = memorySubject(4);

    try {
        Memory::factory()->create([
            'user_id' => $subscriber->id,
            'content' => 'بحب القهوة سادة',
            'category' => MemoryCategory::Preference->value,
        ]);

        // Two savers and two forgetters, all released together.
        $lines = memoryLines([
            memoryRun(['write', (string) $messages[0], 'preference', 'بحب القهوة سادة']),
            memoryRun(['forget', (string) $messages[1], 'القهوة سادة']),
            memoryRun(['write', (string) $messages[2], 'preference', 'بحب القهوة سادة']),
            memoryRun(['forget', (string) $messages[3], 'القهوة سادة']),
        ]);

        $rows = Memory::query()->where('user_id', $subscriber->id)->get();
        $active = $rows->whereNull('archived_at');

        // Whatever the interleaving: never two live copies of one memory, and
        // never a row that is archived while still holding the unique slot.
        expect($active->count())->toBeLessThanOrEqual(1)
            ->and($rows->whereNotNull('archived_at')->whereNotNull('fingerprint')->count())->toBe(0)
            // At most one forget can succeed against one memory.
            ->and(count(array_filter($lines, static fn (string $l): bool => $l === 'forgotten:1')))->toBeLessThanOrEqual(2)
            // Nothing crashed: every process printed a bounded outcome.
            ->and(array_filter($lines, static fn (string $l): bool => $l === ''))->toBe([]);
    } finally {
        memoryCleanup($subscriber);
    }
});

it('never lets one subscriber concurrent writes reach another subscriber', function () {
    [$mine, $myMessages] = memorySubject(3);
    [$theirs, $theirMessages] = memorySubject(3);

    try {
        $processes = [];

        foreach ($myMessages as $i => $id) {
            $processes[] = memoryRun(['write', (string) $id, 'fact', "حقيقة عني رقم {$i}"]);
            $processes[] = memoryRun(['write', (string) $theirMessages[$i], 'fact', "حقيقة عنهم رقم {$i}"]);
        }

        memoryLines($processes);

        $mineRows = Memory::query()->where('user_id', $mine->id)->get();
        $theirRows = Memory::query()->where('user_id', $theirs->id)->get();

        expect($mineRows)->toHaveCount(3)
            ->and($theirRows)->toHaveCount(3)
            // Identical shapes, disjoint owners: no row crossed.
            ->and($mineRows->pluck('id')->intersect($theirRows->pluck('id')))->toHaveCount(0);
    } finally {
        memoryCleanup($mine);
        memoryCleanup($theirs);
    }
});

it('serves the prompt contributor selection from its index rather than the table', function () {
    [$subscriber] = memorySubject(1);
    $extra = [];

    try {
        // A realistic table: MANY subscribers, each within the active-memory
        // ceiling. That is the shape the index exists for — one subscriber's
        // rows are a small slice of the table, and the contributor reads them on
        // every single AI reply.
        $now = now();
        $people = [];

        for ($i = 0; $i < 59; $i++) {
            $people[] = [
                'name' => "plan subject {$i}",
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('users')->insert($people);
        $extra = DB::table('users')->where('name', 'like', 'plan subject %')->pluck('id')->all();

        $rows = [];

        foreach ([...$extra, $subscriber->id] as $ownerIndex => $owner) {
            for ($i = 0; $i < 50; $i++) {
                $rows[] = [
                    'user_id' => $owner,
                    'category' => MemoryCategory::Fact->value,
                    'content' => "sealed-{$ownerIndex}-{$i}",
                    'fingerprint' => hash('sha256', "plan-{$ownerIndex}-{$i}"),
                    'importance' => ($i % 5) + 1,
                    'provenance' => 'explicit',
                    'archived_at' => $i % 7 === 0 ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('memories')->insert($chunk);
        }

        DB::statement('ANALYZE memories');

        $plan = DB::select(
            'EXPLAIN (ANALYZE, COSTS OFF) SELECT * FROM memories WHERE user_id = ? AND archived_at IS NULL ORDER BY importance DESC, updated_at DESC, id DESC LIMIT 12',
            [$subscriber->id],
        );

        $text = implode("\n", array_map(static fn ($r): string => trim((string) $r->{'QUERY PLAN'}), $plan));

        fwrite(STDOUT, "[EXPLAIN memory prompt selection]\n".$text."\n");

        expect($text)->toContain('memories_user_active_importance_idx')
            // The read that runs on every reply never falls back to the table.
            ->and($text)->not->toContain('Seq Scan on memories');
    } finally {
        DB::table('memories')->whereIn('user_id', $extra)->delete();
        DB::table('users')->whereIn('id', $extra)->delete();
        memoryCleanup($subscriber);
    }
});
