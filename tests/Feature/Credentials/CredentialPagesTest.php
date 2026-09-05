<?php

declare(strict_types=1);

use App\Enums\CredentialStatus;
use App\Livewire\Dashboard\Ai\Health as HealthPage;
use App\Livewire\Dashboard\Ai\Providers as ProvidersPage;
use App\Models\AuditLog;
use App\Models\ProviderCredential;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Credentials\CredentialManager;
use App\Support\Rbac\Role;
use App\Support\Security\SecretString;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = c3Catalog();
    c3VaultOn();
});

it('credential routes: guest → login; no role, legacy admin, operations, finance, support → 403; super_admin → redirect + pending row', function () {
    $groq = $this->fx['groq'];
    $url = route('dashboard.ai.credentials.store', $groq);

    $this->post($url, ['secret' => 'gsk_GUEST'])->assertRedirect(route('login'));

    rbacSync();
    foreach ([User::factory()->create(['is_admin' => false]), User::factory()->create(['is_admin' => true]), userWithRole(Role::Operations), userWithRole(Role::Finance), userWithRole(Role::Support)] as $user) {
        $this->actingAs($user)->post($url, ['secret' => 'gsk_DENIED_0000'])->assertForbidden();
    }

    expect(ProviderCredential::count())->toBe(0);

    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->post($url, ['secret' => 'gsk_PAGE_SECRET_1234', 'label' => 'ops key'])
        ->assertRedirect(route('dashboard.ai.providers', ['open' => $groq->id]))
        ->assertSessionHas('credential_status');

    $row = ProviderCredential::firstOrFail();
    expect($row->status)->toBe(CredentialStatus::Pending)->and($row->label)->toBe('ops key')->and($row->last4)->toBe('1234');
});

it('never lets the secret out: not in the session on validation errors, not in audit, logs, the rendered page or the Livewire snapshot', function () {
    $groq = $this->fx['groq'];
    $secret = 'gsk_LEAK_SENTINEL_DO_NOT_SHOW_5678';
    $super = userWithRole(Role::SuperAdmin);

    // Validation redirect (missing label type) must not re-flash the secret as old input.
    $this->actingAs($super)->from(route('dashboard.ai.providers'))
        ->post(route('dashboard.ai.credentials.store', $groq), ['secret' => $secret, 'label' => str_repeat('x', 200)])
        ->assertSessionHasErrors('label')
        ->assertSessionMissing('_old_input.secret');

    $handler = new TestHandler;
    Log::driver()->getLogger()->pushHandler($handler);

    $this->actingAs($super)->post(route('dashboard.ai.credentials.store', $groq), ['secret' => $secret])->assertRedirect();

    $logs = collect($handler->getRecords())->map(fn ($r) => $r['message'].' '.json_encode($r['context']))->implode("\n");
    $row = ProviderCredential::firstOrFail();

    expect($logs)->not->toContain($secret)
        ->and(json_encode(AuditLog::all()->pluck('metadata')))->not->toContain($secret)
        ->and($row->getAttribute('ciphertext'))->not->toContain($secret)
        ->and(json_encode($row->toArray()))->not->toContain('ciphertext'); // hidden from any serialization

    $page = Livewire::actingAs($super)->test(ProvidersPage::class, ['open' => $groq->id]);
    $page->assertOk()->assertSee($row->fingerprint)->assertSee($row->last4)->assertDontSee($secret)->assertDontSee('test-groq-key');

    expect(json_encode($page->snapshot))->not->toContain($secret)
        ->and(json_encode($page->snapshot))->not->toContain('ciphertext')
        // The environment key is shown as a fingerprint only — never its value nor its last4.
        ->and($page->html())->toContain(SecretString::fingerprintOf('test-groq-key'))
        ->and($page->html())->not->toContain('-key</');
});

it('activate and revoke through the forms; revoke needs the typed provider key', function () {
    $groq = $this->fx['groq'];
    $super = userWithRole(Role::SuperAdmin);
    $this->actingAs($super);
    $row = app(CredentialManager::class)->create($groq, new SecretString('gsk_FORM_4321'));

    $this->post(route('dashboard.ai.credentials.activate', $row))->assertRedirect()->assertSessionHas('credential_status');
    expect($row->fresh()->status)->toBe(CredentialStatus::Active);

    $this->post(route('dashboard.ai.credentials.revoke', $row), ['confirmation' => 'nope'])->assertRedirect()->assertSessionHas('credential_error');
    expect($row->fresh()->status)->toBe(CredentialStatus::Active);

    $this->post(route('dashboard.ai.credentials.revoke', $row), ['confirmation' => 'groq'])->assertRedirect()->assertSessionHas('credential_status');
    expect($row->fresh()->status)->toBe(CredentialStatus::Revoked);

    // Operations cannot activate even when they know the URL.
    $pending = app(CredentialManager::class)->create($groq, new SecretString('gsk_FORM_8765'));
    $this->actingAs(userWithRole(Role::Operations))->post(route('dashboard.ai.credentials.activate', $pending))->assertForbidden();
    expect($pending->fresh()->status)->toBe(CredentialStatus::Pending);
});

it('health page: super_admin and operations only; nav link follows the permission', function () {
    $this->get(route('dashboard.ai.health'))->assertRedirect(route('login'));

    rbacSync();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('dashboard.ai.health'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.ai.health'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard.ai.health'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard.ai.health'))->assertOk()->assertSee(route('dashboard.ai.health'));
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.ai.health'))->assertOk();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard'))->assertDontSee(route('dashboard.ai.health'));

    Livewire::actingAs(userWithRole(Role::Finance))->test(HealthPage::class)->assertForbidden();
});

it('Test Connection from the page: finance (view only) is refused inside the action; operations runs it and sees a safe result', function () {
    $groq = $this->fx['groq'];
    Http::fake(['api.groq.com/*' => Http::response(['data' => [['id' => 'llama-3.3-70b-versatile']]], 200)]);

    Livewire::actingAs(userWithRole(Role::Finance))
        ->test(ProvidersPage::class, ['open' => $groq->id])
        ->assertOk()
        ->assertDontSee('تشغيل الاستدلال المفوتر')
        ->call('runCheck', $groq->id, 'auth')
        ->assertForbidden();

    $page = Livewire::actingAs(userWithRole(Role::Operations))
        ->test(ProvidersPage::class, ['open' => $groq->id])
        ->call('runCheck', $groq->id, 'auth')
        ->assertSee('سليم')
        ->assertSee('catalog_models_known');

    expect(json_encode($page->snapshot))->not->toContain('test-groq-key');

    // Billable inference without the typed word is refused and records nothing in the ledger.
    $page->call('runCheck', $groq->id, 'inference')->assertSee('COST');
    expect(UsageEvent::count())->toBe(0);
});
