<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Enums\CredentialStatus;
use App\Exceptions\Ai\LastViableRouteException;
use App\Exceptions\Ai\RoutingChangeConfirmationRequired;
use App\Exceptions\Credentials\CredentialLifecycleException;
use App\Exceptions\Credentials\VaultUnavailableException;
use App\Models\AiProvider;
use App\Models\ProviderCredential;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Permission;
use App\Support\Security\SecretString;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * The ONLY lifecycle writer of provider_credentials (Phase C3):
 *
 *   create   → a new row is inserted `pending` (sealed by the vault) and has
 *              NO effect on the runtime; a rotation is just a create with
 *              rotated_from_id pointing at the current active row;
 *   activate → inside ONE transaction with the parent ai_providers row locked
 *              FOR UPDATE: the row must still be pending and must open with
 *              the current vault (a row that cannot be decrypted is never
 *              activated); the previous active row is revoked and the new one
 *              activated together — any failure leaves the old row active;
 *   revoke   → pending or active → revoked (append-only history). Revoking
 *              the active credential in `vault` mode when the provider has no
 *              environment key would leave it without any credential, so the
 *              C2 routing simulation runs first (last viable route blocked,
 *              selected-route change needs the typed confirmation).
 *
 * Every transition is audited in the same transaction with the fingerprint
 * and last4 only. Permission: ai.credentials.manage (server-side).
 */
class CredentialManager
{
    public function __construct(
        private readonly CredentialVault $vault,
        private readonly CredentialResolver $resolver,
        private readonly RoutingSimulator $simulator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException|VaultUnavailableException|CredentialLifecycleException
     */
    public function create(AiProvider $provider, #[SensitiveParameter] SecretString $secret, ?string $label = null): ProviderCredential
    {
        $this->authorize();

        if ($secret->isEmpty() || preg_match('/\s/', $secret->reveal()) === 1) {
            throw new CredentialLifecycleException('المفتاح فارغ أو يحتوي مسافات.');
        }

        if (mb_strlen($secret->reveal()) > 4096) {
            throw new CredentialLifecycleException('المفتاح أطول من الحد المسموح.');
        }

        $sealed = $this->vault->seal($provider->key, $secret);

        return DB::transaction(function () use ($provider, $sealed, $label): ProviderCredential {
            $locked = AiProvider::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();
            $active = $this->activeOf($locked);

            $credential = ProviderCredential::query()->create([
                'provider_id' => $locked->id,
                'label' => $label !== null && trim($label) !== '' ? mb_substr(trim($label), 0, 120) : null,
                'kind' => 'api_key',
                'ciphertext' => $sealed->ciphertext,
                'key_id' => $sealed->keyId,
                'fingerprint' => $sealed->fingerprint,
                'last4' => $sealed->last4,
                'status' => CredentialStatus::Pending,
                'rotated_from_id' => $active?->id,
                'created_by' => Auth::id(),
                'created_by_ref' => $this->actorRef(),
            ]);

            // Audit keys deliberately avoid the words the redactor treats as
            // secrets (credential/secret/key): these values are display forms.
            $this->audit->record(AuditActions::AiCredentialCreated, $credential, [
                'vault_row' => ['from' => null, 'to' => $this->snapshot($credential)],
            ], [
                'provider' => $locked->key,
                'rotation_of' => $active?->fingerprint,
                'same_as_env' => $this->resolver->envFingerprint($locked->key) === $sealed->fingerprint,
            ]);

            return $credential;
        });
    }

    /**
     * @throws AuthorizationException|CredentialLifecycleException
     */
    public function activate(ProviderCredential $credential): ProviderCredential
    {
        $this->authorize();

        return DB::transaction(function () use ($credential): ProviderCredential {
            $provider = AiProvider::query()->whereKey($credential->provider_id)->lockForUpdate()->firstOrFail();
            $locked = ProviderCredential::query()->whereKey($credential->id)->firstOrFail();

            if (! $locked->isPending()) {
                throw new CredentialLifecycleException("المفتاح [{$locked->fingerprint}] ليس قيد الانتظار (حالته: {$locked->status->label()}).");
            }

            $outcome = $this->vault->open($locked, $provider->key);

            if (! $outcome->isOk()) {
                throw new CredentialLifecycleException("لا يمكن تفعيل المفتاح [{$locked->fingerprint}]: تعذّر فتحه بالخزنة الحالية ({$outcome->failure}).");
            }

            $previous = $this->activeOf($provider);
            $now = CarbonImmutable::now();

            if ($previous !== null) {
                $previous->forceFill(['status' => CredentialStatus::Revoked, 'revoked_at' => $now, 'revoked_by_ref' => $this->actorRef()])->save();
            }

            $locked->forceFill(['status' => CredentialStatus::Active, 'activated_at' => $now, 'last_verified_at' => $now])->save();

            $this->audit->record(AuditActions::AiCredentialActivated, $locked, [
                'active_fingerprint' => ['from' => $previous?->fingerprint, 'to' => $locked->fingerprint],
            ], [
                'provider' => $provider->key,
                'revoked_previous_id' => $previous?->id,
                'credentials_mode' => $this->resolver->mode(),
            ]);

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException|CredentialLifecycleException|LastViableRouteException|RoutingChangeConfirmationRequired
     */
    public function revoke(ProviderCredential $credential, ?string $confirmation = null): ProviderCredential
    {
        $this->authorize();

        return DB::transaction(function () use ($credential, $confirmation): ProviderCredential {
            $provider = AiProvider::query()->whereKey($credential->provider_id)->lockForUpdate()->firstOrFail();
            $locked = ProviderCredential::query()->whereKey($credential->id)->firstOrFail();

            if ($locked->status === CredentialStatus::Revoked) {
                throw new CredentialLifecycleException("المفتاح [{$locked->fingerprint}] ملغى بالفعل.");
            }

            $simulation = null;

            if ($locked->isActive() && $this->resolver->mode() === CredentialResolver::MODE_VAULT && $this->resolver->envFingerprint($provider->key) === null) {
                // The provider would be left without any credential: same
                // protection as disabling it (Phase C2).
                $simulation = $this->simulateWithout($provider, $confirmation);
            }

            $locked->forceFill(['status' => CredentialStatus::Revoked, 'revoked_at' => CarbonImmutable::now(), 'revoked_by_ref' => $this->actorRef()])->save();

            $this->audit->record(AuditActions::AiCredentialRevoked, $locked, [
                'status' => ['from' => $credential->status->value, 'to' => CredentialStatus::Revoked->value],
            ], array_filter([
                'provider' => $provider->key,
                'fingerprint' => $locked->fingerprint,
                'simulation' => $simulation,
            ], static fn ($v) => $v !== null));

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LastViableRouteException|RoutingChangeConfirmationRequired
     */
    private function simulateWithout(AiProvider $provider, ?string $confirmation): array
    {
        $before = $this->simulator->proposed();
        $after = $this->simulator->proposed([$provider->id => ['is_enabled' => false]]);

        if (! $after->hasRoute()) {
            throw LastViableRouteException::for($provider->key.' (credential)');
        }

        $confirmed = false;

        if ($before->selectedHandle() !== $after->selectedHandle()) {
            if ($confirmation === null || trim($confirmation) !== $after->selectedHandle()) {
                throw new RoutingChangeConfirmationRequired($before->selectedHandle(), $after->selectedHandle());
            }

            $confirmed = true;
        }

        return ['before' => $before->selectedHandle(), 'after' => $after->selectedHandle(), 'confirmed' => $confirmed];
    }

    private function activeOf(AiProvider $provider): ?ProviderCredential
    {
        return ProviderCredential::query()->where('provider_id', $provider->id)->where('status', CredentialStatus::Active->value)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ProviderCredential $credential): array
    {
        return [
            'id' => $credential->id,
            'label' => $credential->label,
            'fingerprint' => $credential->fingerprint,
            'last4' => $credential->last4,
            'key_id' => $credential->key_id,
            'status' => $credential->status->value,
        ];
    }

    private function actorRef(): string
    {
        $id = Auth::id();

        return $id !== null ? 'user:'.$id : (app()->runningInConsole() ? 'console' : 'system');
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(): void
    {
        $user = Auth::user();

        if ($user !== null) {
            if (! $user->can(Permission::AiCredentialsManage->value)) {
                throw new AuthorizationException('Missing permission ['.Permission::AiCredentialsManage->value.'].');
            }

            return;
        }

        if (! app()->runningInConsole()) {
            throw new AuthorizationException('Unauthenticated credential write.');
        }
    }
}
