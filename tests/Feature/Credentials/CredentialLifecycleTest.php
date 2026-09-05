<?php

declare(strict_types=1);

use App\Enums\CredentialStatus;
use App\Enums\HealthCheckKind;
use App\Enums\HealthCheckStatus;
use App\Exceptions\Ai\LastViableRouteException;
use App\Exceptions\Ai\RoutingChangeConfirmationRequired;
use App\Exceptions\Credentials\CredentialLifecycleException;
use App\Exceptions\Credentials\VaultUnavailableException;
use App\Models\AuditLog;
use App\Models\ProviderCredential;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Ai\Health\ProviderHealthService;
use App\Services\Audit\AuditLogger;
use App\Services\Credentials\CredentialManager;
use App\Services\Credentials\CredentialResolver;
use App\Services\Credentials\CredentialVault;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use App\Support\Security\SecretString;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $this->fx = c3Catalog();
    c3VaultOn();
    config(['ai.credentials_mode' => 'vault']);
    $this->manager = app(CredentialManager::class);
});

it('create → pending has no runtime effect; test connection on the pending row; activate → previous active revoked in the same transaction', function () {
    $groq = $this->fx['groq'];

    $first = $this->manager->create($groq, new SecretString('gsk_FIRST_SECRET_1111'), 'first');

    expect($first->status)->toBe(CredentialStatus::Pending)
        ->and($first->fingerprint)->toBe(SecretString::fingerprintOf('gsk_FIRST_SECRET_1111'))
        ->and($first->last4)->toBe('1111')
        ->and(app(CredentialResolver::class)->resolve('groq')->source->value)->toBe('env'); // pending ⇒ runtime still on env

    $created = AuditLog::where('action', AuditActions::AiCredentialCreated)->firstOrFail();
    expect(json_encode($created->metadata))->not->toContain('FIRST_SECRET')
        ->and($created->changes()['vault_row']['to']['fingerprint'])->toBe($first->fingerprint)
        ->and($created->context()['same_as_env'])->toBeFalse();

    // Test Connection against the PENDING row: the request carries the pending secret, not the env key.
    Http::fake(['api.groq.com/*' => Http::response(['data' => [['id' => 'llama-3.3-70b-versatile']]], 200)]);
    $check = app(ProviderHealthService::class)->run($groq, HealthCheckKind::Auth, credential: $first);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer gsk_FIRST_SECRET_1111') && str_ends_with($r->url(), '/models'));
    expect($check->status)->toBe(HealthCheckStatus::Ok)
        ->and($check->credential_id)->toBe($first->id)
        ->and($check->details['catalog_models_known'])->toBe(['llama-3.3-70b-versatile'])
        ->and($first->fresh()->last_verified_at)->not->toBeNull();

    $this->manager->activate($first);
    expect($first->fresh()->status)->toBe(CredentialStatus::Active)
        ->and(app(CredentialResolver::class)->resolve('groq')->secret?->reveal())->toBe('gsk_FIRST_SECRET_1111');

    // Rotation: a new pending row; the first stays active until the new one is activated.
    $second = $this->manager->create($groq, new SecretString('gsk_SECOND_SECRET_2222'));
    expect($second->rotated_from_id)->toBe($first->id)
        ->and(app(CredentialResolver::class)->resolve('groq')->secret?->reveal())->toBe('gsk_FIRST_SECRET_1111');

    $this->manager->activate($second);

    expect($second->fresh()->status)->toBe(CredentialStatus::Active)
        ->and($first->fresh()->status)->toBe(CredentialStatus::Revoked)
        ->and($first->fresh()->revoked_at)->not->toBeNull()
        ->and(ProviderCredential::where('provider_id', $groq->id)->where('status', 'active')->count())->toBe(1)
        ->and(app(CredentialResolver::class)->resolve('groq')->secret?->reveal())->toBe('gsk_SECOND_SECRET_2222');

    $activated = AuditLog::where('action', AuditActions::AiCredentialActivated)->latest('id')->firstOrFail();
    expect($activated->changes()['active_fingerprint'])->toBe(['from' => $first->fingerprint, 'to' => $second->fingerprint])
        ->and($activated->context()['revoked_previous_id'])->toBe($first->id);
});

it('refuses to create an empty / whitespace secret, and anything when the vault is unavailable', function () {
    $groq = $this->fx['groq'];

    expect(fn () => $this->manager->create($groq, new SecretString('   ')))->toThrow(CredentialLifecycleException::class)
        ->and(fn () => $this->manager->create($groq, new SecretString('has space')))->toThrow(CredentialLifecycleException::class);

    c3VaultOff();
    expect(fn () => app(CredentialManager::class)->create($groq, new SecretString('gsk_x')))->toThrow(VaultUnavailableException::class)
        ->and(ProviderCredential::count())->toBe(0)
        ->and(AuditLog::where('action', 'like', 'ai.credentials.%')->count())->toBe(0);
});

it('activation fails closed: a non-pending row, an undecryptable row, or an audit failure leaves the OLD credential active', function () {
    $groq = $this->fx['groq'];
    $old = $this->manager->create($groq, new SecretString('gsk_OLD_3333'));
    $this->manager->activate($old);

    // Already active → refused.
    expect(fn () => $this->manager->activate($old))->toThrow(CredentialLifecycleException::class, 'قيد الانتظار');

    // A pending row sealed with a key the vault no longer has → refused, old stays active.
    $pending = $this->manager->create($groq, new SecretString('gsk_NEW_4444'));
    $keyB = c3VaultOn(c3Key());

    expect(fn () => app(CredentialManager::class)->activate($pending))->toThrow(CredentialLifecycleException::class, 'تعذّر فتحه')
        ->and($pending->fresh()->status)->toBe(CredentialStatus::Pending)
        ->and($old->fresh()->status)->toBe(CredentialStatus::Active);

    // Audit failure → whole activation rolled back (old active, new pending).
    c3VaultOn($keyB); // pending row is undecryptable under B: re-create one under B first
    $pendingB = app(CredentialManager::class)->create($groq, new SecretString('gsk_NEW_B_5555'));
    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('audit down'));
    $manager = new CredentialManager(app(CredentialVault::class), app(CredentialResolver::class), app(RoutingSimulator::class), $audit);

    expect(fn () => $manager->activate($pendingB))->toThrow(RuntimeException::class)
        ->and($pendingB->fresh()->status)->toBe(CredentialStatus::Pending)
        ->and($old->fresh()->status)->toBe(CredentialStatus::Active)
        ->and(ProviderCredential::where('status', 'active')->count())->toBe(1);
});

it('revoke: pending or active → revoked; revoking the active vault credential without an env key runs the routing simulation', function () {
    ['groq' => $groq, 'openai' => $openai, 'mini' => $mini] = $this->fx;

    $pending = $this->manager->create($groq, new SecretString('gsk_P_6666'));
    $this->manager->revoke($pending);
    expect($pending->fresh()->status)->toBe(CredentialStatus::Revoked)
        ->and(fn () => $this->manager->revoke($pending->fresh()))->toThrow(CredentialLifecycleException::class, 'ملغى بالفعل');

    $active = $this->manager->create($groq, new SecretString('gsk_A_7777'));
    $this->manager->activate($active);

    // groq has NO env key now: revoking the only credential moves chat to openai → typed confirmation.
    config(['ai.providers.groq.api_key' => '']);

    expect(fn () => app(CredentialManager::class)->revoke($active))->toThrow(RoutingChangeConfirmationRequired::class)
        ->and($active->fresh()->status)->toBe(CredentialStatus::Active);

    // …and if openai were disabled it would be the last viable route → refused.
    $mini->update(['is_enabled' => false]);
    CatalogCache::flush();
    expect(fn () => app(CredentialManager::class)->revoke($active, 'openai:gpt-4.1-mini'))->toThrow(LastViableRouteException::class);
    $mini->update(['is_enabled' => true]);
    CatalogCache::flush();

    app(CredentialManager::class)->revoke($active, 'openai:gpt-4.1-mini');
    $log = AuditLog::where('action', AuditActions::AiCredentialRevoked)->latest('id')->firstOrFail();

    expect($active->fresh()->status)->toBe(CredentialStatus::Revoked)
        ->and($log->context()['simulation'])->toMatchArray(['before' => 'groq:llama-3.3-70b-versatile', 'after' => 'openai:gpt-4.1-mini', 'confirmed' => true])
        ->and(app(CredentialResolver::class)->resolve('groq')->source->value)->toBe('none');
});

it('enforces ai.credentials.manage server-side for every lifecycle action', function () {
    $groq = $this->fx['groq'];
    $pending = $this->manager->create($groq, new SecretString('gsk_RBAC_8888'));

    $this->actingAs(userWithRole(Role::Operations));
    $manager = app(CredentialManager::class);

    expect(fn () => $manager->create($groq, new SecretString('gsk_x_9999')))->toThrow(AuthorizationException::class)
        ->and(fn () => $manager->activate($pending))->toThrow(AuthorizationException::class)
        ->and(fn () => $manager->revoke($pending))->toThrow(AuthorizationException::class)
        ->and($pending->fresh()->status)->toBe(CredentialStatus::Pending)
        ->and(ProviderCredential::count())->toBe(1);
});
