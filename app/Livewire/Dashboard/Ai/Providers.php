<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Ai;

use App\Contracts\Ai\SupportsHealthChecks;
use App\Enums\AiOperation;
use App\Enums\CredentialStatus;
use App\Enums\HealthCheckKind;
use App\Enums\HealthCheckTrigger;
use App\Exceptions\Credentials\CredentialLifecycleException;
use App\Exceptions\Credentials\OutboundBlockedException;
use App\Livewire\Dashboard\Ai\Concerns\HandlesCatalogWrites;
use App\Models\AiProvider;
use App\Models\ProviderCredential;
use App\Models\ProviderHealthCheck;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\CatalogAdmin;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Ai\Health\ProviderHealthService;
use App\Services\Credentials\CredentialManager;
use App\Services\Credentials\CredentialResolver;
use App\Services\Credentials\CredentialVault;
use App\Support\Rbac\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * AI providers as operational data (Phase C2) + credentials and Test
 * Connection (Phase C3). `ai.providers.view` opens the page; editing needs
 * `ai.providers.manage`; the credential forms post to plain routes behind
 * `ai.credentials.manage` (the secret is never a Livewire property); Test
 * Connection needs `ai.credentials.test` (re-checked by the service).
 *
 * Secrets are shown ONLY as fingerprint (+ last4 for vault rows). The env key
 * appears as fingerprint only. is_primary stays read-only; the database
 * base_url is stored-only and can only be exercised as a Test Connection
 * candidate — the runtime endpoint remains config/env.
 */
#[Title('مزوّدو الذكاء الاصطناعي | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Providers extends Component
{
    use HandlesCatalogWrites;

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** Provider whose credential panel is expanded (also set by the POST redirects). */
    #[Url]
    public ?int $open = null;

    /** Typed confirmation for a BILLABLE inference probe. */
    public string $inferenceConfirmation = '';

    /** @var array<int, array<string, mixed>> provider id => last manual result (safe fields only) */
    public array $healthResults = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::AiProvidersView->value) ?? false, 403);
    }

    public function edit(int $id): void
    {
        abort_unless(auth()->user()?->can(Permission::AiProvidersManage->value) ?? false, 403);

        $provider = AiProvider::query()->findOrFail($id);

        $this->editing = $provider->id;
        $this->form = [
            'name' => (string) $provider->name,
            'base_url' => (string) ($provider->base_url ?? ''),
            'priority' => (string) $provider->priority,
            'is_enabled' => $provider->is_enabled ? '1' : '0',
            'capabilities' => array_values((array) ($provider->capabilities ?? [])),
        ];
        $this->cancelConfirmation();
        $this->notice = null;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->form = [];
        $this->cancelConfirmation();
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    public function save(CatalogAdmin $admin): void
    {
        $provider = AiProvider::query()->findOrFail((int) $this->editing);

        $saved = $this->attemptCatalogWrite(fn (?string $confirmation): string => 'تم حفظ المزوّد «'.$admin->updateProvider($provider, [
            'name' => $this->form['name'] ?? '',
            'base_url' => $this->form['base_url'] ?? null,
            'priority' => $this->form['priority'] ?? 0,
            'is_enabled' => ($this->form['is_enabled'] ?? '0') === '1',
            'capabilities' => $this->form['capabilities'] ?? [],
        ], $confirmation)->key.'».');

        if ($saved) {
            $this->editing = null;
            $this->form = [];
        }
    }

    /**
     * Test Connection (manual). $credentialId targets a specific vault row
     * (e.g. a pending one before activation); $candidate exercises the stored
     * database base_url instead of the config endpoint.
     */
    public function runCheck(int $providerId, string $kind, ?int $credentialId = null, bool $candidate = false): void
    {
        $health = app(ProviderHealthService::class);
        abort_unless(auth()->user()?->can(Permission::AiCredentialsTest->value) ?? false, 403);

        $provider = AiProvider::query()->findOrFail($providerId);
        $kindEnum = HealthCheckKind::tryFrom($kind) ?? HealthCheckKind::Connectivity;
        $credential = $credentialId === null ? null : ProviderCredential::query()->where('provider_id', $provider->id)->findOrFail($credentialId);
        $this->open = $provider->id;

        try {
            $check = $health->run($provider, $kindEnum, HealthCheckTrigger::Manual, $credential, $candidate, $kindEnum->billable() ? $this->inferenceConfirmation : null);
        } catch (CredentialLifecycleException|OutboundBlockedException $e) {
            $this->healthResults[$provider->id] = ['error' => $e->getMessage()];

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->inferenceConfirmation = '';
        $this->healthResults[$provider->id] = [
            'kind' => $check->kind->label(),
            'status' => $check->status->value,
            'status_label' => $check->status->label(),
            'latency_ms' => $check->latency_ms,
            'http_status' => $check->http_status,
            'error_code' => $check->error_code,
            'credential_source' => $check->credential_source->label(),
            'candidate' => $check->candidate_base_url,
            'cost_incurred' => $check->cost_incurred,
            'usage_event_id' => $check->usage_event_id,
            'details' => $check->details,
        ];
    }

    public function render(AiManager $aiManager, CatalogSourceResolver $resolver, CredentialResolver $credentials, CredentialVault $vault, CredentialManager $manager)
    {
        $user = auth()->user();
        $providers = AiProvider::query()->withCount('models')->orderByDesc('priority')->orderBy('id')->get();
        $credentialRows = ProviderCredential::query()->whereIn('provider_id', $providers->pluck('id'))->orderByDesc('id')->get()->groupBy('provider_id');
        $latest = ProviderHealthCheck::query()->whereIn('provider_id', $providers->pluck('id'))->orderByDesc('checked_at')->orderByDesc('id')->get()->unique('provider_id')->keyBy('provider_id');
        $mode = $credentials->mode();

        $rows = $providers->map(function (AiProvider $provider) use ($aiManager, $manager, $credentials, $credentialRows, $latest, $mode): array {
            $known = $aiManager->has($provider->key);
            $resolved = $known ? $credentials->resolve($provider->key) : null;
            $adapter = $known ? $aiManager->provider($provider->key) : null;

            return [
                'provider' => $provider,
                'driver_known' => $known,
                'configured' => $adapter?->isConfigured(),
                'env_fingerprint' => $credentials->envFingerprint($provider->key),
                'runtime_source' => $resolved?->source->label(),
                'runtime_failure' => $resolved?->failure,
                'runtime_fingerprint' => $resolved?->fingerprint,
                'credentials' => ($credentialRows->get($provider->id) ?? collect())->sortBy(static fn (ProviderCredential $c): int => match ($c->status) {
                    CredentialStatus::Active => 0, CredentialStatus::Pending => 1, CredentialStatus::Revoked => 2,
                })->values(),
                'last_health' => $latest->get($provider->id),
                'active_id' => ($credentialRows->get($provider->id) ?? collect())->firstWhere('status', CredentialStatus::Active)?->id,
                'verified' => ($credentialRows->get($provider->id) ?? collect())->mapWithKeys(fn (ProviderCredential $c): array => [$c->id => $c->isPending() && $manager->isVerified($c)])->all(),
                'auth_probe' => $adapter instanceof SupportsHealthChecks ? $adapter->healthCapabilities()->nonBillableAuthProbe : false,
                'mode' => $mode,
            ];
        });

        return view('livewire.dashboard.ai.providers', [
            'rows' => $rows,
            'canManage' => $user?->can(Permission::AiProvidersManage->value) ?? false,
            'canManageCredentials' => $user?->can(Permission::AiCredentialsManage->value) ?? false,
            'canTest' => $user?->can(Permission::AiCredentialsTest->value) ?? false,
            'operations' => AiOperation::cases(),
            'preferred' => (string) config('ai.provider', 'groq'),
            'sourceMode' => $resolver->mode(),
            'sourceActive' => $resolver->activeName(),
            'credentialsMode' => $mode,
            'vaultAvailable' => $vault->available(),
            'vaultKeyId' => $vault->keyId(),
            'inferenceWord' => ProviderHealthService::INFERENCE_CONFIRMATION,
            'forceWord' => CredentialManager::FORCE_CONFIRMATION,
            'verificationWindow' => CredentialManager::verificationWindowMinutes(),
        ]);
    }
}
