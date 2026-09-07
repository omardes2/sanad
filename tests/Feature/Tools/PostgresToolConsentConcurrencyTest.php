<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Models\AuditLog;
use App\Models\ToolConsent;
use App\Models\User;
use App\Services\Tools\ToolConsentService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for Phase F1 on PostgreSQL (separate PHP processes,
 * no shared transaction, no in-process claim):
 *  - 6 concurrent FIRST grants of one (subscriber, capability) ⇒ exactly one
 *    row, one winner, five stale, one audit — the unique index arbitrates the
 *    write that has no row to lock;
 *  - 6 concurrent revokes from the same version ⇒ one winner, five stale, the
 *    version moves once;
 *  - a mixed race of grants and revokes from the same version ⇒ ONE of them
 *    wins, the projection is exactly one row at version+1 with a coherent
 *    status, and the audit count matches the number of writes.
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

function consentRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:tool-consent-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/** @return list<string> */
function consentOutcomes(array $processes): array
{
    $outcomes = [];

    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

function consentCleanup(User $user): void
{
    $ids = ToolConsent::query()->where('subscriber_id', $user->id)->pluck('id');
    AuditLog::query()->where('subject_type', (new ToolConsent)->getMorphClass())->whereIn('subject_id', $ids)->delete();
    DB::table('tool_consents')->whereIn('id', $ids)->delete();
    $user->delete();
}

it('of 6 concurrent FIRST grants exactly one row exists: one winner, five stale, one audit', function () {
    $user = User::factory()->create(['is_admin' => false]);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = consentRun(['grant', (string) $user->id, ToolCapability::TasksWrite->value, '0']);
        }
        $outcomes = consentOutcomes($processes);
        $rows = ToolConsent::query()->where('subscriber_id', $user->id)->get();

        expect(array_filter($outcomes, fn ($o) => $o === 'ok:1'))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and($rows)->toHaveCount(1)
            ->and($rows[0]->version)->toBe(1)
            ->and($rows[0]->status->value)->toBe('granted')
            ->and($rows[0]->granted_at)->not->toBeNull()
            ->and($rows[0]->revoked_at)->toBeNull()
            ->and(AuditLog::query()->where('subject_type', $rows[0]->getMorphClass())->where('subject_id', $rows[0]->id)->count())->toBe(1);
    } finally {
        consentCleanup($user);
    }
});

it('of 6 concurrent revokes from the same version exactly one wins; the version moves once', function () {
    $user = User::factory()->create(['is_admin' => false]);

    try {
        $first = consentRun(['grant', (string) $user->id, ToolCapability::MemoryRead->value, '0']);
        $first->wait();
        expect(trim($first->getOutput()))->toBe('ok:1');

        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = consentRun(['revoke', (string) $user->id, ToolCapability::MemoryRead->value, '1']);
        }
        $outcomes = consentOutcomes($processes);
        $row = ToolConsent::query()->where('subscriber_id', $user->id)->where('capability', ToolCapability::MemoryRead->value)->firstOrFail();

        expect(array_filter($outcomes, fn ($o) => $o === 'ok:2'))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and($row->version)->toBe(2)
            ->and($row->status->value)->toBe('revoked')
            ->and($row->revoked_at)->not->toBeNull()
            ->and(AuditLog::query()->where('subject_type', $row->getMorphClass())->where('subject_id', $row->id)->count())->toBe(2); // the grant + this revoke
    } finally {
        consentCleanup($user);
    }
});

it('a mixed grant-vs-revoke race from the same version leaves ONE deterministic current state and no corrupted projection', function () {
    $user = User::factory()->create(['is_admin' => false]);

    try {
        $first = consentRun(['grant', (string) $user->id, ToolCapability::RemindersWrite->value, '0']);
        $first->wait();
        expect(trim($first->getOutput()))->toBe('ok:1');

        // Three revokes and three grants, all claiming the same version they read.
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = consentRun([$i % 2 === 0 ? 'revoke' : 'grant', (string) $user->id, ToolCapability::RemindersWrite->value, '1']);
        }
        $outcomes = consentOutcomes($processes);
        $row = ToolConsent::query()->where('subscriber_id', $user->id)->where('capability', ToolCapability::RemindersWrite->value)->firstOrFail();

        // EXACTLY ONE write happens: the first revoke to take the lock. Whoever arrives before it with a grant is
        // refused as `unchanged` (the row is already granted) and whoever arrives after it is stale — the split
        // between those two depends on lock order, but the invariant does not: one winner, no other write.
        expect(array_filter($outcomes, fn ($o) => $o === 'ok:2'))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale' || $o === 'rejected:unchanged'))->toHaveCount(5)
            ->and(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1)
            ->and(ToolConsent::query()->where('subscriber_id', $user->id)->count())->toBe(1)
            ->and($row->version)->toBe(2)
            ->and($row->status->value)->toBe('revoked')
            ->and($row->granted_at)->not->toBeNull()   // when it was granted is still recorded
            ->and($row->revoked_at)->not->toBeNull()
            ->and(AuditLog::query()->where('subject_type', $row->getMorphClass())->where('subject_id', $row->id)->count())->toBe(2)
            // The state a reader sees is the projection, and it is coherent with the audit trail.
            ->and(app(ToolConsentService::class)->granted($user->id, ToolCapability::RemindersWrite))->toBeFalse();

        $state = consentRun(['state', (string) $user->id, ToolCapability::RemindersWrite->value]);
        $state->wait();
        expect(trim($state->getOutput()))->toBe('revoked:2');
    } finally {
        consentCleanup($user);
    }
});
