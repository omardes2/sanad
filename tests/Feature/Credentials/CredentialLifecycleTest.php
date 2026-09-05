<?php

declare(strict_types=1);

use App\Enums\CredentialStatus;
use App\Enums\HealthCheckKind;
use App\Enums\HealthCheckStatus;
use App\Exceptions\Ai\LastViableRouteException;
use App\Exceptions\Ai\RoutingChangeConfirmationRequired;
use App\Exceptions\Credentials\CredentialLifecycleException;
use App\Exceptions\Credentials\VaultUnavailableException;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\ProviderCredential;
use App\Models\ProviderHealthCheck;
use App\Providers\Ai\OpenAICompatibleChatProvider;
use App\Services\Ai\AiManager;
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

    $this->manager->activate($first, null);
    expect($first->fresh()->status)->toBe(CredentialStatus::Active)
        ->and(app(CredentialResolver::class)->resolve('groq')->secret?->reveal())->toBe('gsk_FIRST_SECRET_1111');

    // Rotation: a new pending row; the first stays active until the new one is activated.
    $second = $this->manager->create($groq, new SecretString('gsk_SECOND_SECRET_2222'));
    expect($second->rotated_from_id)->toBe($first->id)
        ->and(app(CredentialResolver::class)->resolve('groq')->secret?->reveal())->toBe('gsk_FIRST_SECRET_1111');

    c3Verify($second);
    $this->manager->activate($second, $first->id);

    expect($second->fresh()->status)->toBe(CredentialStatus::Active)
        ->and($first->fresh()->status)->toBe(CredentialStatus::Revoked)
        ->and($first->fresh()->revoked_at)->not->toBeNull()
        ->and(ProviderCredential::where('provider_id', $groq->id)->where('status', 'active')->count())->toBe(1)
        ->and(app(CredentialResolver::class)->resolve('groq')->secret?->reveal())->toBe('gsk_SECOND_SECRET_2222');

    $activated = AuditLog::where('action', AuditActions::AiCredentialActivated)->latest('id')->firstOrFail();
    expect($activated->changes()['active_fingerprint'])->toBe(['from' => $first->fingerprint, 'to' => $second->fingerprint])
        ->and($activated->context()['revoked_previous_id'])->toBe($first->id)
        ->and($activated->context()['verified'])->toBeTrue()
        ->and($activated->context()['forced'])->toBeFalse()
        ->and($activated->context()['expected_current_active_id'])->toBe($first->id);
});

it('activation requires a recent SUCCESSFUL auth verification of the same credential; unverified, failed, stale or foreign verifications are refused', function () {
    $groq = $this->fx['groq'];
    $row = $this->manager->create($groq, new SecretString('gsk_VERIFY_ME_4444'));
    $other = $this->manager->create($groq, new SecretString('gsk_OTHER_ROW_5555'));

    // Nothing verified yet.
    expect(fn () => $this->manager->activate($row, null))->toThrow(CredentialLifecycleException::class, 'فحص مصادقة ناجح');

    // A FAILED probe does not count; a probe of ANOTHER row does not count; a stale probe does not count.
    c3Verify($row, 'failed');
    c3Verify($other, 'ok');
    c3Verify($row, 'ok', now()->subMinutes(CredentialManager::verificationWindowMinutes() + 5)->toImmutable());
    expect(fn () => $this->manager->activate($row, null))->toThrow(CredentialLifecycleException::class)
        ->and($row->fresh()->status)->toBe(CredentialStatus::Pending);

    // A connectivity probe is not an auth verification either.
    ProviderHealthCheck::query()->create(['provider_id' => $groq->id, 'kind' => 'connectivity', 'trigger' => 'manual', 'status' => 'ok', 'credential_id' => $row->id, 'credential_source' => 'vault', 'checked_at' => now()]);
    expect(fn () => $this->manager->activate($row, null))->toThrow(CredentialLifecycleException::class);

    c3Verify($row);
    $this->manager->activate($row, null);
    expect($row->fresh()->status)->toBe(CredentialStatus::Active)->and($other->fresh()->status)->toBe(CredentialStatus::Pending);
});

it('refuses a stale activation: the active row changed since the caller looked — the loser stays pending, nothing is revoked', function () {
    $groq = $this->fx['groq'];
    $a = $this->manager->create($groq, new SecretString('gsk_A_6661'));
    $b = $this->manager->create($groq, new SecretString('gsk_B_6662'));
    $c = $this->manager->create($groq, new SecretString('gsk_C_6663'));
    c3Verify($a);
    c3Verify($b);
    c3Verify($c);

    $this->manager->activate($a, null);

    // Two admins both saw "a" as the current active and each try to activate their own row.
    $this->manager->activate($b, $a->id);

    expect(fn () => $this->manager->activate($c, $a->id))->toThrow(CredentialLifecycleException::class, 'تعارض')
        ->and($c->fresh()->status)->toBe(CredentialStatus::Pending)
        ->and($b->fresh()->status)->toBe(CredentialStatus::Active)
        ->and($a->fresh()->status)->toBe(CredentialStatus::Revoked)
        ->and(AuditLog::where('action', AuditActions::AiCredentialActivated)->count())->toBe(2);

    // Wrong expectation when nothing is active is a conflict too; the right one (b) works and c can still be activated later.
    expect(fn () => $this->manager->activate($c, null))->toThrow(CredentialLifecycleException::class, 'تعارض');
    $this->manager->activate($c, $b->id);
    expect($c->fresh()->status)->toBe(CredentialStatus::Active)->and($b->fresh()->status)->toBe(CredentialStatus::Revoked);
});

it('forced activation: super_admin only, typed UNVERIFIED, only for adapters WITHOUT a non-billable auth probe, audited as forced', function () {
    // groq declares an auth probe → the force path is refused even for super_admin.
    $groq = $this->fx['groq'];
    $g = $this->manager->create($groq, new SecretString('gsk_FORCE_7771'));
    expect(fn () => $this->manager->activateUnverified($g, null, CredentialManager::FORCE_CONFIRMATION))->toThrow(CredentialLifecycleException::class, 'المسار العادي')
        ->and($g->fresh()->status)->toBe(CredentialStatus::Pending);

    // A compatible endpoint with no declared probe.
    app(AiManager::class)->extend('compat', fn ($c, array $config) => new class('compat', $config) extends OpenAICompatibleChatProvider {});
    config(['ai.providers.compat' => ['base_url' => 'https://compat.example.com/v1', 'api_key' => '', 'model' => 'm']]);
    $compat = AiProvider::factory()->create(['key' => 'compat', 'driver' => 'compat']);
    $row = $this->manager->create($compat, new SecretString('compat_FORCE_7772'));

    expect(fn () => $this->manager->activate($row, null))->toThrow(CredentialLifecycleException::class, 'فحص مصادقة ناجح')
        ->and(fn () => $this->manager->activateUnverified($row, null, 'wrong'))->toThrow(CredentialLifecycleException::class, 'UNVERIFIED')
        ->and($row->fresh()->status)->toBe(CredentialStatus::Pending);

    $this->manager->activateUnverified($row, null, 'UNVERIFIED');
    $log = AuditLog::where('action', AuditActions::AiCredentialActivatedUnverified)->firstOrFail();

    expect($row->fresh()->status)->toBe(CredentialStatus::Active)
        ->and($log->context())->toMatchArray(['forced' => true, 'verified' => false, 'provider' => 'compat'])
        ->and(AuditLog::where('action', AuditActions::AiCredentialActivated)->count())->toBe(0);

    // Operations (not super_admin) cannot use the force path even with a granted manage permission.
    $ops = userWithRole(Role::Operations);
    Spatie\Permission\Models\Role::findByName(Role::Operations->value)->givePermissionTo('ai.credentials.manage');
    $this->actingAs($ops->fresh());
    $row2 = app(CredentialManager::class)->create($compat, new SecretString('compat_FORCE_7773'));
    expect(fn () => app(CredentialManager::class)->activateUnverified($row2, $row->id, 'UNVERIFIED'))->toThrow(AuthorizationException::class)
        ->and($row2->fresh()->status)->toBe(CredentialStatus::Pending);
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
    c3Verify($old);
    $this->manager->activate($old, null);

    // Already active → refused.
    expect(fn () => $this->manager->activate($old, null))->toThrow(CredentialLifecycleException::class, 'قيد الانتظار');

    // A pending row sealed with a key the vault no longer has → refused, old stays active.
    $pending = $this->manager->create($groq, new SecretString('gsk_NEW_4444'));
    c3Verify($pending);
    $keyB = c3VaultOn(c3Key());

    expect(fn () => app(CredentialManager::class)->activate($pending, $old->id))->toThrow(CredentialLifecycleException::class, 'تعذّر فتحه')
        ->and($pending->fresh()->status)->toBe(CredentialStatus::Pending)
        ->and($old->fresh()->status)->toBe(CredentialStatus::Active);

    // Audit failure → whole activation rolled back (old active, new pending).
    c3VaultOn($keyB); // pending row is undecryptable under B: re-create one under B first
    $pendingB = app(CredentialManager::class)->create($groq, new SecretString('gsk_NEW_B_5555'));
    c3Verify($pendingB);
    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('audit down'));
    $manager = new CredentialManager(app(CredentialVault::class), app(CredentialResolver::class), app(RoutingSimulator::class), $audit, app(AiManager::class));

    expect(fn () => $manager->activate($pendingB, $old->id))->toThrow(RuntimeException::class, 'audit down')
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
    c3Verify($active);
    $this->manager->activate($active, null);

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
        ->and(fn () => $manager->activate($pending, null))->toThrow(AuthorizationException::class)
        ->and(fn () => $manager->activateUnverified($pending, null, 'UNVERIFIED'))->toThrow(AuthorizationException::class)
        ->and(fn () => $manager->revoke($pending))->toThrow(AuthorizationException::class)
        ->and($pending->fresh()->status)->toBe(CredentialStatus::Pending)
        ->and(ProviderCredential::count())->toBe(1);
});
