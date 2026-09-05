<?php

declare(strict_types=1);

namespace App\Services\Ai\Health;

use App\Contracts\Ai\SupportsHealthChecks;
use App\Data\Ai\Health\HealthProbeContext;
use App\Data\Ai\Health\HealthProbeResult;
use App\Data\Billing\UsageRecord;
use App\Enums\CredentialSource;
use App\Enums\HealthCheckKind;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckTrigger;
use App\Enums\UsageDimension;
use App\Enums\UsageEventOutcome;
use App\Exceptions\Credentials\CredentialLifecycleException;
use App\Exceptions\Credentials\OutboundBlockedException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ProviderCredential;
use App\Models\ProviderHealthCheck;
use App\Services\Ai\AiManager;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\UsageRecorder;
use App\Services\Credentials\CredentialResolver;
use App\Services\Credentials\CredentialVault;
use App\Services\Credentials\ProviderRuntimeConfigFactory;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Permission;
use App\Support\Security\OutboundGuard;
use App\Support\Security\SecretString;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Runs provider health probes and records their history (Phase C3).
 *
 *  - manual runs need `ai.credentials.test` and are throttled per provider;
 *    scheduled runs are console-only and limited to the non-billable `auth`
 *    kind of adapters that declare it;
 *  - the probe may target the EFFECTIVE credential (what the runtime uses),
 *    an explicit vault row (a pending credential before activation), and/or
 *    a CANDIDATE base URL (the stored database base_url) — the candidate is
 *    re-validated and pinned at call time (OutboundGuard); the config URL
 *    keeps today's behaviour;
 *  - an `inference` probe is billable: manual only, requires the typed
 *    confirmation, and its consumption is written to usage_events as a
 *    SYSTEM-attributed `health_check` row (no subscriber, no quota, company
 *    cost) linked from provider_health_checks.usage_event_id.
 */
class ProviderHealthService
{
    public const INFERENCE_CONFIRMATION = 'COST';

    public function __construct(
        private readonly AiManager $manager,
        private readonly CredentialResolver $resolver,
        private readonly CredentialVault $vault,
        private readonly ProviderRuntimeConfigFactory $configs,
        private readonly OutboundGuard $guard,
        private readonly UsageRecorder $recorder,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException|CredentialLifecycleException|OutboundBlockedException
     */
    public function run(
        AiProvider $provider,
        HealthCheckKind $kind,
        HealthCheckTrigger $trigger = HealthCheckTrigger::Manual,
        ?ProviderCredential $credential = null,
        bool $candidateBaseUrl = false,
        ?string $confirmation = null,
    ): ProviderHealthCheck {
        $this->authorize($trigger);

        if ($kind->billable()) {
            if ($trigger !== HealthCheckTrigger::Manual) {
                throw new CredentialLifecycleException('فحص الاستدلال المفوتر يدوي فقط.');
            }

            if (trim((string) $confirmation) !== self::INFERENCE_CONFIRMATION) {
                throw new CredentialLifecycleException('فحص الاستدلال مفوتر: اكتب «'.self::INFERENCE_CONFIRMATION.'» للتأكيد.');
            }
        }

        if ($trigger === HealthCheckTrigger::Manual) {
            $this->throttle($provider);
        }

        if (! $this->manager->has($provider->key)) {
            return $this->store($provider, $kind, $trigger, new HealthProbeResult(HealthCheckStatus::Skipped, errorCode: 'no_adapter'), CredentialSource::None, null, false);
        }

        [$config, $source, $credentialId] = $this->runtimeConfig($provider, $credential, $candidateBaseUrl);
        $adapter = $this->manager->providerWith($provider->key, $config);

        if (! $adapter instanceof SupportsHealthChecks) {
            return $this->store($provider, $kind, $trigger, new HealthProbeResult(HealthCheckStatus::Skipped, errorCode: 'unsupported'), $source, $credentialId, $candidateBaseUrl);
        }

        if ($kind === HealthCheckKind::Auth && ! $adapter->healthCapabilities()->nonBillableAuthProbe) {
            // Never assume a free authenticated probe; connectivity is NOT auth.
            return $this->store($provider, $kind, $trigger, new HealthProbeResult(HealthCheckStatus::Skipped, errorCode: 'unsupported'), $source, $credentialId, $candidateBaseUrl);
        }

        if ($kind !== HealthCheckKind::Connectivity && $source === CredentialSource::None) {
            return $this->store($provider, $kind, $trigger, new HealthProbeResult(HealthCheckStatus::Failed, errorCode: 'missing_credential'), $source, $credentialId, $candidateBaseUrl);
        }

        $models = AiModel::query()->where('provider_id', $provider->id)->where('is_enabled', true)->orderByDesc('priority')->pluck('external_id')->all();
        $context = new HealthProbeContext(
            connectTimeout: (int) config('ai.health.connect_timeout', 5),
            timeout: $kind->billable() ? (int) config('ai.timeout', 20) : (int) config('ai.health.timeout', 10),
            model: $models[0] ?? (is_string($config['model'] ?? null) ? $config['model'] : null),
            expectedModels: $models,
        );

        $result = $adapter->healthCheck($kind, $context);
        $check = $this->store($provider, $kind, $trigger, $result, $source, $credentialId, $candidateBaseUrl);

        if ($kind->billable() && ($result->inputTokens !== null || $result->outputTokens !== null)) {
            $this->recordUsage($provider, $check, $result, $context);
        }

        if ($credential !== null && $result->status === HealthCheckStatus::Ok && $kind !== HealthCheckKind::Connectivity) {
            $credential->forceFill(['last_verified_at' => CarbonImmutable::now()])->save();
        }

        if ($trigger === HealthCheckTrigger::Manual) {
            $this->audit->record(AuditActions::AiProviderHealthChecked, $check, [], [
                'provider' => $provider->key,
                'kind' => $kind->value,
                'status' => $result->status->value,
                'source' => $source->value,
                'vault_row_id' => $credentialId,
                'candidate_base_url' => $candidateBaseUrl,
                'cost_incurred' => $check->cost_incurred,
                'usage_event_id' => $check->usage_event_id,
            ]);
        }

        return $check;
    }

    /**
     * @return array{0: array<string, mixed>, 1: CredentialSource, 2: ?int}
     *
     * @throws CredentialLifecycleException|OutboundBlockedException
     */
    private function runtimeConfig(AiProvider $provider, ?ProviderCredential $credential, bool $candidateBaseUrl): array
    {
        $baseUrl = null;
        $options = [];

        if ($candidateBaseUrl) {
            $stored = trim((string) $provider->base_url);

            if ($stored === '') {
                throw new CredentialLifecycleException('لا يوجد base_url مخزَّن لهذا المزوّد لاختباره.');
            }

            $pinned = $this->guard->pin($stored);
            $baseUrl = $pinned->url;
            $options = $pinned->httpOptions();
        }

        if ($credential !== null) {
            if ($credential->provider_id !== $provider->id) {
                throw new CredentialLifecycleException('المفتاح لا يخص هذا المزوّد.');
            }

            $outcome = $this->vault->open($credential, $provider->key);

            if (! $outcome->isOk() || $outcome->secret === null) {
                throw new CredentialLifecycleException("تعذّر فتح المفتاح [{$credential->fingerprint}] ({$outcome->failure}).");
            }

            return [$this->configs->with($provider->key, $outcome->secret, $baseUrl, $options), CredentialSource::Vault, $credential->id];
        }

        $resolved = $this->resolver->resolve($provider->key);

        if ($resolved->failedClosed()) {
            throw new CredentialLifecycleException("المزوّد مغلق: مفتاح الخزنة الفعّال لا يمكن فتحه ({$resolved->failure}).");
        }

        /** @var SecretString|null $secret */
        $secret = $resolved->usable() ? $resolved->secret : null;

        return [$this->configs->with($provider->key, $secret, $baseUrl, $options), $resolved->source, $resolved->credentialId];
    }

    private function store(AiProvider $provider, HealthCheckKind $kind, HealthCheckTrigger $trigger, HealthProbeResult $result, CredentialSource $source, ?int $credentialId, bool $candidate): ProviderHealthCheck
    {
        return ProviderHealthCheck::query()->create([
            'provider_id' => $provider->id,
            'kind' => $kind,
            'trigger' => $trigger,
            'status' => $result->status,
            'credential_id' => $credentialId,
            'credential_source' => $source,
            'candidate_base_url' => $candidate,
            'latency_ms' => $result->latencyMs,
            'http_status' => $result->httpStatus,
            'error_class' => $result->errorClass !== null ? mb_substr($result->errorClass, 0, 191) : null,
            'error_code' => $result->errorCode,
            'cost_incurred' => $kind->billable() && ($result->inputTokens !== null || $result->outputTokens !== null),
            'checked_by_ref' => $this->actorRef(),
            'details' => $result->details !== [] ? $result->details : null,
            'checked_at' => CarbonImmutable::now(),
        ]);
    }

    private function recordUsage(AiProvider $provider, ProviderHealthCheck $check, HealthProbeResult $result, HealthProbeContext $context): void
    {
        $event = $this->recorder->record(new UsageRecord(
            subscriber: null,
            dimension: UsageDimension::AiReply,
            idempotencyKey: 'health-check:'.$check->id.':'.Str::uuid(),
            correlationId: 'health-check:'.$check->id,
            operation: 'health_check',
            provider: $provider->key,
            model: $result->reportedModel ?? (string) $context->model,
            channel: 'admin',
            inputUnits: (int) ($result->inputTokens ?? 0),
            outputUnits: (int) ($result->outputTokens ?? 0),
            durationMs: $result->latencyMs,
            outcome: UsageEventOutcome::Succeeded,
            metadata: ['health_check_id' => $check->id, 'attribution' => 'system'],
            routedModel: $context->model,
        ))->event;

        $check->forceFill(['usage_event_id' => $event->id, 'cost_incurred' => true])->save();
    }

    private function throttle(AiProvider $provider): void
    {
        $key = 'sanad:health:'.$provider->id;
        $max = (int) config('ai.health.manual_per_minute', 6);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new CredentialLifecycleException('تجاوزت حد الفحوصات اليدوية لهذا المزوّد؛ حاول بعد دقيقة.');
        }

        RateLimiter::hit($key, 60);
    }

    private function actorRef(): string
    {
        $id = Auth::id();

        return $id !== null ? 'user:'.$id : (app()->runningInConsole() ? 'console' : 'system');
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(HealthCheckTrigger $trigger): void
    {
        $user = Auth::user();

        if ($user !== null) {
            if (! $user->can(Permission::AiCredentialsTest->value)) {
                throw new AuthorizationException('Missing permission ['.Permission::AiCredentialsTest->value.'].');
            }

            return;
        }

        if (! app()->runningInConsole()) {
            throw new AuthorizationException('Unauthenticated health check.');
        }
    }
}
