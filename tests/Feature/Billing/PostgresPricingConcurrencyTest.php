<?php

declare(strict_types=1);

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\ModelPrice;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel test for price publication (Phase B2): many OS processes
 * publish a price for the SAME model at the same instant, against the same
 * PostgreSQL database. PriceBook locks the PARENT ai_models row before it
 * examines periods, so publications are serialised even for a model that has
 * NO price yet (there is no price row to lock in that case).
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

function pricingConcurrencyModel(): AiModel
{
    $provider = AiProvider::create([
        'key' => 'concurrency-'.uniqid(), 'name' => 'Concurrency', 'driver' => 'openai',
        'capabilities' => ['chat'], 'is_enabled' => false, 'is_primary' => false, 'priority' => 0,
    ]);

    return AiModel::create([
        'provider_id' => $provider->id, 'external_id' => 'race-model', 'name' => 'race',
        'aliases' => [], 'capabilities' => ['chat'], 'supports_tools' => false, 'is_enabled' => false, 'priority' => 0,
    ]);
}

function pricingConcurrencyCleanup(AiModel $model): void
{
    // Since Phase C2 every publication is audited; remove those rows too so a
    // non-RefreshDatabase test leaves nothing behind for the rest of the suite.
    $priceIds = ModelPrice::where('model_id', $model->id)->pluck('id');
    AuditLog::where('subject_type', (new ModelPrice)->getMorphClass())->whereIn('subject_id', $priceIds)->delete();
    ModelPrice::where('model_id', $model->id)->delete();
    $providerId = $model->provider_id;
    AiModel::whereKey($model->id)->delete();
    AiProvider::whereKey($providerId)->delete();
}

it('publishing the same period concurrently for a model with NO prior price yields exactly one effective period', function () {
    $model = pricingConcurrencyModel();
    $from = '2026-06-01T00:00:00Z';

    try {
        $processes = [];
        for ($i = 0; $i < 12; $i++) {
            $p = new Process(['php', 'artisan', 'sanad:ai-price-probe', (string) $model->id, $from, '1.'.$i, '2'], base_path());
            $p->start();
            $processes[] = $p;
        }

        $published = 0;
        $rejected = 0;
        foreach ($processes as $p) {
            $p->wait();
            $out = trim($p->getOutput());
            $published += $out === 'published' ? 1 : 0;
            $rejected += $out === 'rejected' ? 1 : 0;
        }

        $rows = ModelPrice::where('model_id', $model->id)->get();

        expect($published)->toBe(1)
            ->and($rejected)->toBe(11)
            ->and($rows)->toHaveCount(1)
            ->and($rows->first()->effective_until)->toBeNull();
    } finally {
        pricingConcurrencyCleanup($model);
    }
});

it('concurrent publications with distinct starts serialise into a contiguous, non-overlapping history with one open period', function () {
    $model = pricingConcurrencyModel();

    try {
        $processes = [];
        for ($i = 0; $i < 10; $i++) {
            $from = sprintf('2026-06-01T00:%02d:00Z', $i); // ten distinct starts, one minute apart
            $p = new Process(['php', 'artisan', 'sanad:ai-price-probe', (string) $model->id, $from, '1', '2'], base_path());
            $p->start();
            $processes[] = $p;
        }
        foreach ($processes as $p) {
            $p->wait();
        }

        $rows = ModelPrice::where('model_id', $model->id)->orderBy('effective_from')->get();

        // Whatever the interleaving, every accepted period must end exactly where
        // the next begins, and only the last is open. (Arrivals that lost the
        // race to a LATER start are rejected as overlaps — never squeezed in.)
        expect($rows->count())->toBeGreaterThanOrEqual(1)
            ->and($rows->whereNull('effective_until')->count())->toBe(1);

        for ($i = 0; $i < $rows->count() - 1; $i++) {
            expect($rows[$i]->effective_until?->toIso8601String())->toBe($rows[$i + 1]->effective_from->toIso8601String());
        }

        expect($rows->last()->effective_until)->toBeNull();
    } finally {
        pricingConcurrencyCleanup($model);
    }
});
