<?php

declare(strict_types=1);

use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\ProviderCredential;
use App\Models\ProviderHealthCheck;
use App\Services\Credentials\CredentialVault;
use App\Support\Security\SecretString;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel test for credential activation (Phase C3): many OS
 * processes activate VERIFIED pending credentials of the SAME provider at the
 * same instant, all starting from the same current active row (what each
 * admin saw). CredentialManager locks the parent ai_providers row, re-reads
 * the current active row and compares it with the caller's expectation, so
 * exactly ONE activation wins: the old active row is revoked, the winner is
 * active, every other pending row stays PENDING (never revoked, never a
 * silent last-writer-wins), and the partial unique index is never violated.
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

/**
 * @return array{0: AiProvider, 1: int, 2: list<int>, 3: string} provider, current ACTIVE id, verified pending ids, key
 */
function credentialConcurrencyFixture(int $pending): array
{
    $key = c3Key();
    config(['credentials.key' => $key, 'credentials.previous_keys' => '']);
    $vault = app(CredentialVault::class);

    $provider = AiProvider::create([
        'key' => 'cred-race-'.uniqid(), 'name' => 'Credential race', 'driver' => 'groq',
        'capabilities' => ['chat'], 'is_enabled' => false, 'is_primary' => false, 'priority' => 0,
    ]);

    $make = static function (string $status) use ($vault, $provider): ProviderCredential {
        $sealed = $vault->seal($provider->key, new SecretString('gsk_race_'.uniqid()));

        return ProviderCredential::create([
            'provider_id' => $provider->id, 'ciphertext' => $sealed->ciphertext, 'key_id' => $sealed->keyId,
            'fingerprint' => $sealed->fingerprint, 'last4' => $sealed->last4, 'status' => $status,
            'activated_at' => $status === 'active' ? now() : null,
        ]);
    };

    $active = $make('active');
    $ids = [];

    for ($i = 0; $i < $pending; $i++) {
        $row = $make('pending');
        c3Verify($row);
        $ids[] = $row->id;
    }

    return [$provider, $active->id, $ids, $key];
}

function credentialConcurrencyCleanup(AiProvider $provider): void
{
    $ids = ProviderCredential::where('provider_id', $provider->id)->pluck('id');
    AuditLog::where('subject_type', (new ProviderCredential)->getMorphClass())->whereIn('subject_id', $ids)->delete();
    ProviderHealthCheck::where('provider_id', $provider->id)->delete();
    ProviderCredential::where('provider_id', $provider->id)->delete();
    AiProvider::whereKey($provider->id)->delete();
}

it('concurrent activations of DIFFERENT verified pending rows from the SAME current active: exactly one wins, the old one is revoked, the rest stay pending', function () {
    [$provider, $activeId, $ids, $key] = credentialConcurrencyFixture(10);

    try {
        $processes = [];
        foreach ($ids as $id) {
            $p = new Process(['php', 'artisan', 'sanad:ai-credential-probe', (string) $id, (string) $activeId], base_path(), ['CREDENTIALS_KEY' => $key, 'AI_CREDENTIALS_MODE' => 'env']);
            $p->start();
            $processes[] = $p;
        }

        $outputs = [];
        foreach ($processes as $p) {
            $p->wait();
            $outputs[] = trim($p->getOutput());
        }

        $rows = ProviderCredential::where('provider_id', $provider->id)->get();
        $winner = $rows->firstWhere('status', 'active');

        expect(array_count_values($outputs))->toEqualCanonicalizing(['activated' => 1, 'rejected' => 9])
            ->and($rows->where('status', 'active')->count())->toBe(1)
            ->and($winner->id)->toBeIn($ids)
            ->and($rows->firstWhere('id', $activeId)->status->value)->toBe('revoked')
            ->and($rows->where('status', 'revoked')->count())->toBe(1)
            ->and($rows->where('status', 'pending')->count())->toBe(9)
            ->and(AuditLog::where('action', 'ai.credentials.activated')->whereIn('subject_id', $ids)->count())->toBe(1);
    } finally {
        credentialConcurrencyCleanup($provider);
    }
});

it('activating the SAME verified pending credential concurrently succeeds once and is rejected cleanly otherwise', function () {
    [$provider, $activeId, $ids, $key] = credentialConcurrencyFixture(1);

    try {
        $processes = [];
        for ($i = 0; $i < 10; $i++) {
            $p = new Process(['php', 'artisan', 'sanad:ai-credential-probe', (string) $ids[0], (string) $activeId], base_path(), ['CREDENTIALS_KEY' => $key, 'AI_CREDENTIALS_MODE' => 'env']);
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
