<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Contracts\Ai\SupportsHealthChecks;
use App\Enums\CredentialStatus;
use App\Enums\HealthCheckKind;
use App\Enums\HealthCheckStatus;
use App\Exceptions\Ai\LastViableRouteException;
use App\Exceptions\Ai\RoutingChangeConfirmationRequired;
use App\Exceptions\Credentials\CredentialLifecycleException;
use App\Exceptions\Credentials\VaultUnavailableException;
use App\Models\AiProvider;
use App\Models\ProviderCredential;
use App\Models\ProviderHealthCheck;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
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
 *              FOR UPDATE: the row must still be pending, must open with the
 *              current vault, and must carry a RECENT SUCCESSFUL auth
 *              verification (a provider_health_checks row for this very
 *              credential, kind auth, status ok, inside the verification
 *              window); the CURRENT active row is re-read under the lock and
 *              must equal the one the caller saw when it decided to activate
 *              (`expectedCurrentActiveId`) — otherwise the activation is a
 *              stale conflict and is refused, the losing row stays pending
 *              (never revoked, never last-writer-wins); the previous active
 *              row is revoked and the new one activated together — any
 *              failure leaves the old row active;
 *   activateUnverified → the Super Admin force path for a provider whose
 *              adapter declares NO non-billable auth probe: same lock, same
 *              conflict check, typed confirmation, audited as forced /
 *              unverified. Refused for adapters that do have a probe.
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
        private readonly AiManager $manager,
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

    public const FORCE_CONFIRMATION = 'UNVERIFIED';

    /**
     * Normal activation: requires a recent successful auth verification of
     * THIS credential and the caller's view of the current active row.
     *
     * @param  int|null  $expectedCurrentActiveId  the active credential id the caller saw (null = none)
     *
     * @throws AuthorizationException|CredentialLifecycleException
     */
    public function activate(ProviderCredential $credential, ?int $expectedCurrentActiveId): ProviderCredential
    {
        $this->authorize();

        return $this->transition($credential, $expectedCurrentActiveId, forced: false, confirmation: null);
    }

    /**
     * Super Admin force path for a provider WITHOUT a non-billable auth probe
     * (nothing can verify the credential for free). Typed confirmation,
     * audited as forced/unverified. Refused when the adapter has a probe —
     * OpenAI/Groq always go through the normal path.
     *
     * @throws AuthorizationException|CredentialLifecycleException
     */
    public function activateUnverified(ProviderCredential $credential, ?int $expectedCurrentActiveId, ?string $confirmation): ProviderCredential
    {
        $this->authorize();

        if (! (Auth::user()?->hasRole(Role::SuperAdmin->value) ?? app()->runningInConsole())) {
            throw new AuthorizationException('Forced activation is reserved to super_admin.');
        }

        if (trim((string) $confirmation) !== self::FORCE_CONFIRMATION) {
            throw new CredentialLifecycleException('التفعيل بلا تحقق يتطلب كتابة «'.self::FORCE_CONFIRMATION.'».');
        }

        return $this->transition($credential, $expectedCurrentActiveId, forced: true, confirmation: $confirmation);
    }

    /**
     * Whether a credential currently satisfies the verification rule.
     */
    public function isVerified(ProviderCredential $credential): bool
    {
        return $this->latestVerification($credential) !== null;
    }

    public function latestVerification(ProviderCredential $credential): ?ProviderHealthCheck
    {
        return ProviderHealthCheck::query()
            ->where('credential_id', $credential->id)
            ->where('kind', HealthCheckKind::Auth->value)
            ->where('status', HealthCheckStatus::Ok->value)
            ->where('checked_at', '>=', CarbonImmutable::now()->subMinutes(self::verificationWindowMinutes()))
            ->orderByDesc('checked_at')
            ->first();
    }

    public static function verificationWindowMinutes(): int
    {
        return max(1, (int) config('ai.health.verification_window_minutes', 1440));
    }

    /**
     * @throws CredentialLifecycleException
     */
    private function transition(ProviderCredential $credential, ?int $expectedCurrentActiveId, bool $forced, ?string $confirmation): ProviderCredential
    {
        return DB::transaction(function () use ($credential, $expectedCurrentActiveId, $forced): ProviderCredential {
            $provider = AiProvider::query()->whereKey($credential->provider_id)->lockForUpdate()->firstOrFail();
            $locked = ProviderCredential::query()->whereKey($credential->id)->firstOrFail();

            if (! $locked->isPending()) {
                throw new CredentialLifecycleException("المفتاح [{$locked->fingerprint}] ليس قيد الانتظار (حالته: {$locked->status->label()}).");
            }

            // Re-read the CURRENT active row under the lock: it must be the one
            // the caller decided against. A concurrent activation that already
            // won makes this one stale — refused, the row stays pending.
            $previous = $this->activeOf($provider);

            if (($previous?->id) !== $expectedCurrentActiveId) {
                throw new CredentialLifecycleException(sprintf(
                    'تعارض: المفتاح الفعّال تغيّر منذ فتح الصفحة (المتوقع %s، الحالي %s). أعد تحميل الصفحة وراجع ثم أعد المحاولة؛ المفتاح [%s] ما زال قيد الانتظار.',
                    $expectedCurrentActiveId === null ? 'لا شيء' : '#'.$expectedCurrentActiveId,
                    $previous === null ? 'لا شيء' : '#'.$previous->id,
                    $locked->fingerprint,
                ));
            }

            $outcome = $this->vault->open($locked, $provider->key);

            if (! $outcome->isOk()) {
                throw new CredentialLifecycleException("لا يمكن تفعيل المفتاح [{$locked->fingerprint}]: تعذّر فتحه بالخزنة الحالية ({$outcome->failure}).");
            }

            $verification = $this->latestVerification($locked);

            if ($forced) {
                if ($this->supportsAuthProbe($provider)) {
                    throw new CredentialLifecycleException("المزوّد [{$provider->key}] يدعم فحص مصادقة غير مفوتر: استخدم المسار العادي (اختبار ثم تفعيل).");
                }
            } elseif ($verification === null) {
                throw new CredentialLifecycleException("لا يمكن تفعيل المفتاح [{$locked->fingerprint}]: لا يوجد فحص مصادقة ناجح له خلال آخر ".self::verificationWindowMinutes().' دقيقة. اختبره أولًا.');
            }

            $now = CarbonImmutable::now();

            if ($previous !== null) {
                $previous->forceFill(['status' => CredentialStatus::Revoked, 'revoked_at' => $now, 'revoked_by_ref' => $this->actorRef()])->save();
            }

            $locked->forceFill(['status' => CredentialStatus::Active, 'activated_at' => $now, 'last_verified_at' => $verification?->checked_at ?? $locked->last_verified_at])->save();

            $this->audit->record($forced ? AuditActions::AiCredentialActivatedUnverified : AuditActions::AiCredentialActivated, $locked, [
                'active_fingerprint' => ['from' => $previous?->fingerprint, 'to' => $locked->fingerprint],
            ], [
                'provider' => $provider->key,
                'revoked_previous_id' => $previous?->id,
                'expected_current_active_id' => $expectedCurrentActiveId,
                'verified' => $verification !== null,
                'verification_check_id' => $verification?->id,
                'forced' => $forced,
                'credentials_mode' => $this->resolver->mode(),
            ]);

            return $locked;
        });
    }

    private function supportsAuthProbe(AiProvider $provider): bool
    {
        if (! $this->manager->has($provider->key)) {
            return false;
        }

        $adapter = $this->manager->providerWith($provider->key, ['base_url' => 'https://unused.invalid', 'api_key' => null]);

        return $adapter instanceof SupportsHealthChecks && $adapter->healthCapabilities()->nonBillableAuthProbe;
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
