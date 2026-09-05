<?php

declare(strict_types=1);

use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\ProviderCredential;
use App\Services\Credentials\CredentialVault;
use App\Support\Security\SecretString;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel test for credential activation (Phase C3): many OS
 * processes activate credentials of the SAME provider at the same instant.
 * CredentialManager locks the parent ai_providers row before it reads the
 * current active row, so activations serialise: exactly ONE active row
 * survives (the partial unique index is never violated) and every other
 * arrival either activated-then-got-revoked or was rejected cleanly.
 *
 * Runs only on a reachable pgsql connection; the child processes receive the
 * vault key through their environment. Not wrapped in RefreshDatabase; it
 * removes only the rows it created.
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

function credentialConcurrencyFixture(int $pending): array
{
    $key = c3Key();
    config(['credentials.key' => $key, 'credentials.previous_keys' => '']);
    $vault = app(CredentialVault::class);

    $provider = AiProvider::create([
        'key' => 'cred-race-'.uniqid(), 'name' => 'Credential race', 'driver' => 'groq',
        'capabilities' => ['chat'], 'is_enabled' => false, 'is_primary' => false, 'priority' => 0,
    ]);

    $ids = [];
    for ($i = 0; $i < $pending; $i++) {
        $sealed = $vault->seal($provider->key, new SecretString('gsk_race_'.$i.'_'.uniqid()));
        $ids[] = ProviderCredential::create([
            'provider_id' => $provider->id, 'ciphertext' => $sealed->ciphertext, 'key_id' => $sealed->keyId,
            'fingerprint' => $sealed->fingerprint, 'last4' => $sealed->last4, 'status' => 'pending',
        ])->id;
    }

    return [$provider, $ids, $key];
}

function credentialConcurrencyCleanup(AiProvider $provider): void
{
    $ids = ProviderCredential::where('provider_id', $provider->id)->pluck('id');
    AuditLog::where('subject_type', (new ProviderCredential)->getMorphClass())->whereIn('subject_id', $ids)->delete();
    ProviderCredential::where('provider_id', $provider->id)->delete();
    AiProvider::whereKey($provider->id)->delete();
}

it('activating DIFFERENT pending credentials concurrently leaves exactly one active row', function () {
    [$provider, $ids, $key] = credentialConcurrencyFixture(10);

    try {
        $processes = [];
        foreach ($ids as $id) {
            $p = new Process(['php', 'artisan', 'sanad:ai-credential-probe', (string) $id], base_path(), ['CREDENTIALS_KEY' => $key, 'AI_CREDENTIALS_MODE' => 'env']);
            $p->start();
            $processes[] = $p;
        }

        $outputs = [];
        foreach ($processes as $p) {
            $p->wait();
            $outputs[] = trim($p->getOutput());
        }

        $rows = ProviderCredential::where('provider_id', $provider->id)->get();

        expect(array_count_values($outputs))->toBe(['activated' => 10])
            ->and($rows->where('status', 'active')->count())->toBe(1)
            ->and($rows->where('status', 'revoked')->count())->toBe(9)
            ->and($rows->where('status', 'pending')->count())->toBe(0);
    } finally {
        credentialConcurrencyCleanup($provider);
    }
});

it('activating the SAME pending credential concurrently succeeds once and is rejected cleanly otherwise', function () {
    [$provider, $ids, $key] = credentialConcurrencyFixture(1);

    try {
        $processes = [];
        for ($i = 0; $i < 10; $i++) {
            $p = new Process(['php', 'artisan', 'sanad:ai-credential-probe', (string) $ids[0]], base_path(), ['CREDENTIALS_KEY' => $key, 'AI_CREDENTIALS_MODE' => 'env']);
            $p->start();
            $processes[] = $p;
        }

        $outputs = [];
        foreach ($processes as $p) {
            $p->wait();
            $outputs[] = trim($p->getOutput());
        }

        expect(array_count_values($outputs))->toEqualCanonicalizing(['activated' => 1, 'rejected' => 9])
            ->and(ProviderCredential::where('provider_id', $provider->id)->where('status', 'active')->count())->toBe(1);
    } finally {
        credentialConcurrencyCleanup($provider);
    }
});
