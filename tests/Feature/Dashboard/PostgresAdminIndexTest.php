<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * EXPLAIN evidence for the admin reads that run over `tool_invocations` — the
 * table on course to be the largest in the platform.
 *
 * This is the test that decides whether this phase needs a migration. The two
 * indexes the admin list leans on already exist from Phase F2:
 *
 *   tool_invocations_status_created_idx      (status, created_at)
 *   tool_invocations_subscriber_created_idx  (subscriber_id, created_at)
 *
 * If PostgreSQL chooses either of them over a sequential scan on a realistic
 * table, no 65th migration is justified — and inventing an index "to be safe"
 * would be adding a schema change nobody proved was needed.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('EXPLAIN evidence requires the pgsql connection.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('PostgreSQL is not reachable.');
    }
});

/**
 * A realistic table: many subscribers, many invocations each, statuses spread
 * the way production spreads them. A single-subscriber table would let the
 * planner pick a sequential scan for perfectly good reasons and prove nothing.
 */
function admSeedInvocations(int $subscribers = 40, int $each = 60, ?string &$run = null): array
{
    $userIds = [];

    // These tests run against a SHARED PostgreSQL database with no transaction
    // wrapper, so faker's per-process uniqueness is not enough — identities are
    // made explicit to avoid colliding with rows another test left behind.
    $run = bin2hex(random_bytes(4));

    for ($u = 0; $u < $subscribers; $u++) {
        $userIds[] = User::factory()->create([
            'is_admin' => false,
            'email' => "explain.{$run}.{$u}@example.test",
            'phone' => '+97059'.substr((string) (1000000 + $u), -7),
        ])->id;
    }

    $statuses = ToolInvocationStatus::values();
    $rows = [];
    $now = now();
    $n = 0;

    foreach ($userIds as $userId) {
        for ($i = 0; $i < $each; $i++) {
            $n++;
            $status = $statuses[$n % count($statuses)];

            $row = [
                'subscriber_id' => $userId,
                'tool_key' => 'memory.read',
                'tool_version' => 2,
                'capability' => ToolCapability::MemoryRead->value,
                'side_effect' => ToolSideEffect::Read->value,
                'idempotency_key' => "explain:{$run}:{$n}",
                'input_hash' => hash('sha256', $run.$n),
                'input' => '[]',
                'input_fields' => '[]',
                'status' => $status,
                'call_index' => 1,
                'version' => 1,
                'created_at' => $now->copy()->subDays($n % 90),
                'updated_at' => $now,
                // A batch insert requires every row to carry the SAME columns,
                // so the optional ones are declared null here and filled below.
                'output' => null,
                'failure_kind' => null,
                'refusal_reason' => null,
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
            ];

            /*
             * PostgreSQL enforces the lifecycle with CHECK constraints, so a
             * synthetic row has to be a COHERENT one: succeeded needs an output
             * and both timestamps, failed and timed_out need a failure kind and
             * both timestamps, and refused happens before anything runs so it
             * needs a reason and NO started_at. Filling these is not test
             * ceremony — it is what makes the fixture resemble the table the
             * planner will actually see.
             */
            $row = array_merge($row, match ($status) {
                ToolInvocationStatus::Succeeded->value => [
                    'output' => '{"memories_count":1,"truncated":false}',
                    'started_at' => $row['created_at'],
                    'finished_at' => $row['created_at'],
                    'duration_ms' => 12,
                ],
                ToolInvocationStatus::Failed->value, ToolInvocationStatus::TimedOut->value => [
                    'failure_kind' => ToolInvocationFailureKind::Timeout->value,
                    'started_at' => $row['created_at'],
                    'finished_at' => $row['created_at'],
                    'duration_ms' => 900,
                ],
                ToolInvocationStatus::Refused->value => [
                    'refusal_reason' => ToolInvocationRefusalReason::NotGranted->value,
                ],
                default => [],
            });

            $rows[] = $row;

            if (count($rows) >= 500) {
                DB::table('tool_invocations')->insert($rows);
                $rows = [];
            }
        }
    }

    if ($rows !== []) {
        DB::table('tool_invocations')->insert($rows);
    }

    return $userIds;
}

function admExplain(string $sql, array $bindings): string
{
    $plan = DB::select('EXPLAIN (ANALYZE, COSTS OFF) '.$sql, $bindings);

    return implode("\n", array_map(static fn ($r): string => trim((string) $r->{'QUERY PLAN'}), $plan));
}

it('serves the status-over-window admin read from an index, so no new migration is needed', function () {
    $userIds = admSeedInvocations();

    try {
        DB::statement('ANALYZE tool_invocations');

        $text = admExplain(
            'SELECT * FROM tool_invocations WHERE status = ? AND created_at >= ? AND created_at < ? ORDER BY created_at DESC, id DESC LIMIT 25',
            [ToolInvocationStatus::Refused->value, now()->subDays(7), now()->addDay()],
        );

        fwrite(STDOUT, "\n[EXPLAIN tool_invocations by status over window]\n".$text."\n");

        expect($text)->toContain('tool_invocations_status_created_idx')
            ->and($text)->not->toContain('Seq Scan on tool_invocations');
    } finally {
        DB::table('tool_invocations')->whereIn('subscriber_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    }
});

it('serves the subscriber-over-window admin read from an index', function () {
    $userIds = admSeedInvocations();

    try {
        DB::statement('ANALYZE tool_invocations');

        $text = admExplain(
            'SELECT * FROM tool_invocations WHERE subscriber_id = ? AND created_at >= ? AND created_at < ? ORDER BY created_at DESC, id DESC LIMIT 25',
            [$userIds[0], now()->subDays(30), now()->addDay()],
        );

        fwrite(STDOUT, "\n[EXPLAIN tool_invocations by subscriber over window]\n".$text."\n");

        expect($text)->toContain('tool_invocations_subscriber_created_idx')
            ->and($text)->not->toContain('Seq Scan on tool_invocations');
    } finally {
        DB::table('tool_invocations')->whereIn('subscriber_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    }
});

it('serves the exact idempotency-key lookup from its unique index', function () {
    $userIds = admSeedInvocations(10, 30, $run);
    $key = "explain:{$run}:5";

    try {
        DB::statement('ANALYZE tool_invocations');

        $text = admExplain('SELECT * FROM tool_invocations WHERE idempotency_key = ?', [$key]);

        fwrite(STDOUT, "\n[EXPLAIN tool_invocations by idempotency key]\n".$text."\n");

        expect($text)->toContain('tool_invocations_idempotency_key_unique')
            ->and($text)->not->toContain('Seq Scan on tool_invocations');
    } finally {
        DB::table('tool_invocations')->whereIn('subscriber_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    }
});

it('confirms this phase adds no migration at all', function () {
    $files = glob(database_path('migrations/*.php'));

    // 66 is the count as of the recurring-reminders phase, which added exactly
    // one. The admin surface itself still adds none: it reads existing columns
    // and indexes only, and this assertion is what would catch it slipping one
    // in.
    expect($files)->toHaveCount(66);
});
