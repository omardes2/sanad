<?php

declare(strict_types=1);

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ProviderHealthCheck;
use App\Support\Security\SecretString;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for the Phase C4 cutovers: many OS processes apply
 * the SAME previewed cutover at the same instant against the same PostgreSQL
 * database. The setting row / the primary rows are locked and the state the
 * admin saw is re-checked under the lock, so exactly ONE process applies and
 * every other one is a clean stale conflict — never last-writer-wins.
 *
 * Runs only on a reachable pgsql connection. Not wrapped in RefreshDatabase;
 * it creates and removes only its own rows. Child processes get the provider
 * keys through their environment (fingerprints match the health proofs).
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

const C4_GROQ_KEY = 'pg-race-groq-key';
const C4_OPENAI_KEY = 'pg-race-openai-key';

/**
 * @return array{groq: AiProvider, openai: AiProvider}
 */
function cutoverConcurrencyFixture(bool $groqPrimary): array
{
    AppSetting::query()->whereIn('key', ['ai.catalog_source', 'ai.routing.mode'])->delete();
    AppSetting::create(['key' => 'ai.catalog_source', 'value' => 'database']);
    AppSetting::create(['key' => 'ai.routing.mode', 'value' => 'env']);

    $groq = AiProvider::create(['key' => 'groq', 'name' => 'Groq', 'driver' => 'groq', 'capabilities' => ['chat'], 'is_enabled' => true, 'is_primary' => $groqPrimary, 'priority' => 100]);
    $openai = AiProvider::create(['key' => 'openai', 'name' => 'OpenAI', 'driver' => 'openai', 'capabilities' => ['chat'], 'is_enabled' => true, 'is_primary' => false, 'priority' => 10]);
    AiModel::create(['provider_id' => $groq->id, 'external_id' => 'llama-3.3-70b-versatile', 'name' => 'llama', 'aliases' => [], 'capabilities' => ['chat'], 'supports_tools' => true, 'is_enabled' => true, 'priority' => 0]);
    AiModel::create(['provider_id' => $openai->id, 'external_id' => 'gpt-4.1-mini', 'name' => 'mini', 'aliases' => [], 'capabilities' => ['chat'], 'supports_tools' => true, 'is_enabled' => true, 'priority' => 0]);

    foreach ([[$groq, C4_GROQ_KEY], [$openai, C4_OPENAI_KEY]] as [$provider, $key]) {
        ProviderHealthCheck::create([
            'provider_id' => $provider->id, 'kind' => 'auth', 'trigger' => 'manual', 'status' => 'ok', 'credential_source' => 'env',
            'details' => ['credential_fingerprint' => SecretString::fingerprintOf($key)], 'checked_at' => now(),
        ]);
    }

    return ['groq' => $groq, 'openai' => $openai];
}

function cutoverConcurrencyCleanup(): void
{
    $providerIds = AiProvider::whereIn('key', ['groq', 'openai'])->pluck('id');
    // The managed-setting write is audited as settings.updated on the AppSetting row too.
    $settingIds = AppSetting::query()->whereIn('key', ['ai.catalog_source', 'ai.routing.mode'])->pluck('id');
    AuditLog::where('subject_type', (new AppSetting)->getMorphClass())->whereIn('subject_id', $settingIds)->delete();
    AuditLog::whereIn('action', ['ai.routing.mode_changed', 'ai.routing.primary_changed', 'ai.catalog.source_changed', 'ai.routing.env_fallback_used'])->delete();
    ProviderHealthCheck::whereIn('provider_id', $providerIds)->delete();
    AiModel::whereIn('provider_id', $providerIds)->delete();
    AiProvider::whereIn('id', $providerIds)->delete();
    AppSetting::query()->whereIn('key', ['ai.catalog_source', 'ai.routing.mode'])->delete();
}

function cutoverProbe(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:ai-cutover-probe', ...$args], base_path(), [
        'AI_PROVIDER' => 'groq', 'GROQ_API_KEY' => C4_GROQ_KEY, 'OPENAI_API_KEY' => C4_OPENAI_KEY,
        'AI_CATALOG_SOURCE' => '', 'AI_ROUTING_MODE' => '', 'AI_CREDENTIALS_MODE' => '',
    ]);
    $p->start();

    return $p;
}

it('concurrent env→db routing-mode cutovers from the same previewed state: exactly one applies, the rest are stale', function () {
    cutoverConcurrencyFixture(groqPrimary: true);

    try {
        $processes = [];
        for ($i = 0; $i < 10; $i++) {
            $processes[] = cutoverProbe(['mode', 'db', 'env', 'groq:llama-3.3-70b-versatile']);
        }

        $outputs = [];
        foreach ($processes as $p) {
            $p->wait();
            $outputs[] = trim($p->getOutput()) ?: trim($p->getErrorOutput());
        }

        expect(array_count_values($outputs))->toEqualCanonicalizing(['applied' => 1, 'stale' => 9])
            ->and(AppSetting::where('key', 'ai.routing.mode')->value('value'))->toBe('db')
            ->and(AuditLog::where('action', 'ai.routing.mode_changed')->count())->toBe(1);
    } finally {
        cutoverConcurrencyCleanup();
    }
});

it('concurrent setPrimary calls from the same current primary: exactly one wins, the partial unique index holds, the rest are stale', function () {
    $fx = cutoverConcurrencyFixture(groqPrimary: true);

    try {
        $processes = [];
        for ($i = 0; $i < 10; $i++) {
            $processes[] = cutoverProbe(['primary', (string) $fx['openai']->id, (string) $fx['groq']->id, 'groq:llama-3.3-70b-versatile']);
        }

        $outputs = [];
        foreach ($processes as $p) {
            $p->wait();
            $outputs[] = trim($p->getOutput()) ?: trim($p->getErrorOutput());
        }

        // Routing mode is env, so the route does not change: the confirmation is the current handle.
        expect(array_count_values($outputs))->toEqualCanonicalizing(['applied' => 1, 'stale' => 9])
            ->and(AiProvider::where('is_primary', true)->count())->toBe(1)
            ->and(AiProvider::where('is_primary', true)->value('key'))->toBe('openai')
            ->and(AuditLog::where('action', 'ai.routing.primary_changed')->count())->toBe(1);
    } finally {
        cutoverConcurrencyCleanup();
    }
});
