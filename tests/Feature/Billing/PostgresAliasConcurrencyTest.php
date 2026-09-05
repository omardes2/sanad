<?php

declare(strict_types=1);

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel test for catalog writes (Phase C2): many OS processes create
 * a model under the SAME provider at the same instant — different external_ids
 * but the SAME alias — against the same PostgreSQL database. CatalogAdmin locks
 * the parent ai_providers row before it checks uniqueness, so exactly one wins
 * and every other arrival is rejected by the service (never by a crash).
 *
 * Runs only on a reachable pgsql connection; skipped on the SQLite test DB
 * (a :memory: database cannot be shared across processes). Not wrapped in
 * RefreshDatabase; it removes only the rows it created.
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

function aliasConcurrencyProvider(): AiProvider
{
    return AiProvider::create([
        'key' => 'alias-race-'.uniqid(), 'name' => 'Alias race', 'driver' => 'openai',
        'capabilities' => ['chat'], 'is_enabled' => false, 'is_primary' => false, 'priority' => 0,
    ]);
}

function aliasConcurrencyCleanup(AiProvider $provider): void
{
    $ids = AiModel::where('provider_id', $provider->id)->pluck('id');
    AuditLog::where('subject_type', (new AiModel)->getMorphClass())->whereIn('subject_id', $ids)->delete();
    AiModel::where('provider_id', $provider->id)->delete();
    AiProvider::whereKey($provider->id)->delete();
}

it('creating models with the SAME alias concurrently under one provider yields exactly one model', function () {
    $provider = aliasConcurrencyProvider();

    try {
        $processes = [];
        for ($i = 0; $i < 12; $i++) {
            $p = new Process(['php', 'artisan', 'sanad:ai-alias-probe', (string) $provider->id, "distinct-{$i}", 'shared-alias'], base_path());
            $p->start();
            $processes[] = $p;
        }

        $created = 0;
        $rejected = 0;
        foreach ($processes as $p) {
            $p->wait();
            $out = trim($p->getOutput());
            $created += $out === 'created' ? 1 : 0;
            $rejected += $out === 'rejected' ? 1 : 0;
        }

        $models = AiModel::where('provider_id', $provider->id)->get();

        expect($created)->toBe(1)
            ->and($rejected)->toBe(11)
            ->and($models)->toHaveCount(1)
            ->and($models->first()->aliases)->toBe(['shared-alias']);
    } finally {
        aliasConcurrencyCleanup($provider);
    }
});

it('creating the SAME external_id concurrently is rejected cleanly by the service, not by a constraint crash', function () {
    $provider = aliasConcurrencyProvider();

    try {
        $processes = [];
        for ($i = 0; $i < 10; $i++) {
            $p = new Process(['php', 'artisan', 'sanad:ai-alias-probe', (string) $provider->id, 'same-external-id'], base_path());
            $p->start();
            $processes[] = $p;
        }

        $outputs = [];
        foreach ($processes as $p) {
            $p->wait();
            $outputs[] = trim($p->getOutput());
        }

        expect(array_count_values($outputs))->toEqualCanonicalizing(['created' => 1, 'rejected' => 9])
            ->and(AiModel::where('provider_id', $provider->id)->count())->toBe(1);
    } finally {
        aliasConcurrencyCleanup($provider);
    }
});
