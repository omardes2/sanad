<?php

declare(strict_types=1);

use App\Data\Ai\AiMessage;
use App\Data\Ai\AiRequest;
use App\Data\Credentials\OpenOutcome;
use App\Enums\CredentialSource;
use App\Enums\CredentialStatus;
use App\Exceptions\Ai\AiConfigurationException;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\ProviderCredential;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Credentials\CredentialResolver;
use App\Services\Credentials\CredentialVault;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use App\Support\Security\SecretString;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function c3Sealed(string $providerKey, string $secret, CredentialStatus $status = CredentialStatus::Active): ProviderCredential
{
    $provider = AiProvider::query()->where('key', $providerKey)->firstOrFail();
    $sealed = app(CredentialVault::class)->seal($providerKey, new SecretString($secret));

    return ProviderCredential::query()->create([
        'provider_id' => $provider->id, 'ciphertext' => $sealed->ciphertext, 'key_id' => $sealed->keyId,
        'fingerprint' => $sealed->fingerprint, 'last4' => $sealed->last4, 'status' => $status,
        'activated_at' => $status === CredentialStatus::Active ? now() : null,
    ]);
}

beforeEach(function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $this->fx = c3Catalog();
});

it('env mode: the environment key is used even when an active vault credential exists', function () {
    c3VaultOn();
    c3Sealed('groq', 'gsk_VAULT_ACTIVE_0001');
    config(['ai.credentials_mode' => 'env']);

    $resolved = app(CredentialResolver::class)->resolve('groq');

    expect(app(CredentialResolver::class)->mode())->toBe('env')
        ->and($resolved->source)->toBe(CredentialSource::Env)
        ->and($resolved->secret?->reveal())->toBe('test-groq-key')
        ->and($resolved->fingerprint)->toBe(SecretString::fingerprintOf('test-groq-key'))
        ->and($resolved->last4)->toBeNull(); // env keys: fingerprint only

    config(['ai.providers.gemini.api_key' => '']);
    expect(app(CredentialResolver::class)->resolve('gemini')->source)->toBe(CredentialSource::None);
});

it('vault mode: active vault credential wins; none active → env fallback; neither → none', function () {
    c3VaultOn();
    config(['ai.credentials_mode' => 'vault']);
    $resolver = app(CredentialResolver::class);

    // No vault row yet → transition fallback to env.
    expect($resolver->resolve('groq')->source)->toBe(CredentialSource::Env);

    // A PENDING row never affects the runtime.
    c3Sealed('groq', 'gsk_PENDING_0002', CredentialStatus::Pending);
    expect($resolver->resolve('groq')->source)->toBe(CredentialSource::Env);

    $active = c3Sealed('groq', 'gsk_VAULT_ACTIVE_0003');
    $resolved = app(CredentialResolver::class)->resolve('groq');

    expect($resolved->source)->toBe(CredentialSource::Vault)
        ->and($resolved->secret?->reveal())->toBe('gsk_VAULT_ACTIVE_0003')
        ->and($resolved->credentialId)->toBe($active->id)
        ->and($resolved->fingerprint)->toBe($active->fingerprint)
        ->and($resolved->last4)->toBe('0003');

    // openai: no vault row and no env key → none.
    config(['ai.providers.openai.api_key' => '']);
    expect(app(CredentialResolver::class)->resolve('openai')->source)->toBe(CredentialSource::None);
});

it('vault mode: an active credential that cannot be opened FAILS CLOSED (no env fallback), warns, and audits once per window', function () {
    c3VaultOn();
    $active = c3Sealed('groq', 'gsk_WILL_BE_LOCKED_0004');
    config(['ai.credentials_mode' => 'vault']);

    // 1) Master key missing.
    c3VaultOff();
    Log::spy();
    $resolved = app(CredentialResolver::class)->resolve('groq');

    expect($resolved->failedClosed())->toBeTrue()
        ->and($resolved->failure)->toBe(OpenOutcome::VAULT_UNAVAILABLE)
        ->and($resolved->source)->toBe(CredentialSource::None)
        ->and($resolved->secret)->toBeNull()
        ->and($resolved->credentialId)->toBe($active->id);
    Log::shouldHaveReceived('warning')->with('sanad.credentials.failed_closed', Mockery::any())->once();

    app(CredentialResolver::class)->resolve('groq');
    $audits = AuditLog::where('action', AuditActions::AiCredentialResolveFailed)->get();

    expect($audits)->toHaveCount(1)
        ->and($audits->first()->actor)->not->toBe('') // recorded by the acting user's request
        ->and($audits->first()->context()['failure'])->toBe(OpenOutcome::VAULT_UNAVAILABLE)
        ->and(json_encode($audits->first()->metadata))->not->toContain('WILL_BE_LOCKED');

    // 2) Tampered row under a valid key.
    c3VaultOn(c3Key()); // a different key: the row is undecryptable
    $resolved = app(CredentialResolver::class)->resolve('groq');
    expect($resolved->failure)->toBe(OpenOutcome::UNDECRYPTABLE)->and($resolved->secret)->toBeNull();

    // The environment key is still present, and it is deliberately NOT used.
    expect(config('ai.providers.groq.api_key'))->toBe('test-groq-key');
});

it('a failed-closed provider is unconfigured for the manager, skipped by the router with reason credential_failed, and routing moves on', function () {
    c3VaultOn();
    c3Sealed('groq', 'gsk_LOCKED_0005');
    config(['ai.credentials_mode' => 'vault']);
    c3VaultOff();

    $adapter = app(AiManager::class)->provider('groq');

    expect($adapter->isConfigured())->toBeFalse()
        ->and($adapter->credentialFailure())->toBe(OpenOutcome::VAULT_UNAVAILABLE)
        ->and(fn () => $adapter->chat(new AiRequest(messages: [AiMessage::user('x')], temperature: 0.1, maxOutputTokens: 5, timeout: 5)))
        ->toThrow(AiConfigurationException::class);

    $evaluation = app(RoutingSimulator::class)->current();

    expect($evaluation->selectedHandle())->toBe('openai:gpt-4.1-mini')
        ->and($evaluation->candidates[0]['spec']->provider)->toBe('groq')
        ->and($evaluation->candidates[0]['reason'])->toBe('credential_failed');

    // Emergency rollback: AI_CREDENTIALS_MODE=env (config-time override) restores the env key immediately.
    config(['ai.overrides.credentials_mode' => 'env']);
    expect(app(CredentialResolver::class)->mode())->toBe('env')
        ->and(app(AiManager::class)->provider('groq')->isConfigured())->toBeTrue()
        ->and(app(RoutingSimulator::class)->current()->selectedHandle())->toBe('groq:llama-3.3-70b-versatile');
});

it('adapters accept a plain string key (tests / extend factories) and a SecretString alike, revealing only in the Authorization header', function () {
    c3VaultOn();
    c3Sealed('groq', 'gsk_HEADER_0006');
    config(['ai.credentials_mode' => 'vault']);
    Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

    $adapter = app(AiManager::class)->provider('groq');
    $adapter->chat(new AiRequest(messages: [AiMessage::user('x')], temperature: 0.1, maxOutputTokens: 5, timeout: 5));

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer gsk_HEADER_0006'));
    expect($adapter->credentialSource())->toBe('vault');
});
