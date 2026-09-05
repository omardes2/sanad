<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\ProviderCredential;
use App\Services\Credentials\CredentialManager;
use App\Services\Credentials\CredentialResolver;
use App\Services\Credentials\CredentialVault;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use App\Support\Security\SecretString;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('re-encrypts rows sealed by a previous master key in place (same secret, new key_id), skips unopenable rows, and audits', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $fx = c3Catalog();
    config(['ai.credentials_mode' => 'vault']);

    $keyA = c3VaultOn();
    $manager = app(CredentialManager::class);
    $a1 = $manager->create($fx['groq'], new SecretString('gsk_ROT_A1_1111'));
    $manager->activate($a1);
    $a2 = $manager->create($fx['openai'], new SecretString('sk-ROT_A2_2222'));
    $kidA = $a1->key_id;

    // A row sealed by a key nobody has any more.
    c3VaultOn(c3Key());
    $orphan = app(CredentialManager::class)->create($fx['openai'], new SecretString('sk-ORPHAN_3333'));

    // New master key B, A kept as previous.
    $keyB = c3VaultOn(c3Key(), $keyA);
    $kidB = (string) app(CredentialVault::class)->keyId();

    $this->artisan('sanad:credentials:rotate-key')->assertSuccessful();
    expect($a1->fresh()->key_id)->toBe($kidA); // dry run wrote nothing

    $this->artisan('sanad:credentials:rotate-key', ['--apply' => true, '--force' => true])->assertFailed(); // orphan skipped ⇒ non-zero

    expect($a1->fresh()->key_id)->toBe($kidB)
        ->and($a2->fresh()->key_id)->toBe($kidB)
        ->and($a1->fresh()->fingerprint)->toBe($a1->fingerprint) // the SECRET did not change
        ->and($a1->fresh()->status->value)->toBe('active')
        ->and($orphan->fresh()->key_id)->not->toBe($kidB)
        ->and($a1->fresh()->getAttribute('ciphertext'))->not->toBe($a1->getAttribute('ciphertext'));

    // With key B ONLY (A removed), the rotated rows still open; the orphan does not.
    c3VaultOn($keyB);
    expect(app(CredentialResolver::class)->resolve('groq')->secret?->reveal())->toBe('gsk_ROT_A1_1111')
        ->and(app(CredentialVault::class)->open($orphan->fresh(), 'openai')->isOk())->toBeFalse();

    $log = AuditLog::where('action', AuditActions::AiCredentialKeyRotated)->firstOrFail();
    expect($log->context()['to_key_id'])->toBe($kidB)
        ->and(collect($log->context()['reencrypted'])->pluck('id')->all())->toEqualCanonicalizing([$a1->id, $a2->id])
        ->and($log->context()['skipped'][0]['id'])->toBe($orphan->id)
        ->and(json_encode($log->metadata))->not->toContain('ROT_A1');

    // Nothing to do on a second run.
    $this->artisan('sanad:credentials:rotate-key', ['--apply' => true, '--force' => true])->assertFailed(); // orphan still skipped
    expect(ProviderCredential::where('key_id', $kidB)->count())->toBe(2);

    c3VaultOff();
    $this->artisan('sanad:credentials:rotate-key')->assertFailed();
});

it('generate-key prints a base64 key of 32 bytes and writes nothing', function () {
    $this->artisan('sanad:credentials:generate-key')->expectsOutputToContain('base64:')->assertSuccessful();
    expect((string) config('credentials.key'))->toBe('');
});
