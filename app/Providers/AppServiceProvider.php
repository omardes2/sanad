<?php

namespace App\Providers;

use App\Agents\AiAgentOrchestrator;
use App\Agents\MeteredAgentOrchestrator;
use App\Agents\PlaceholderAgentOrchestrator;
use App\Channels\ChannelRegistry;
use App\Contracts\AgentOrchestrator;
use App\Contracts\Ai\CatalogSource;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Billing\UsageEngine;
use App\Services\Billing\UsageLimitResponder;
use App\Services\Billing\UsageRecorder;
use App\Services\Settings\SettingsRepository;
use App\Support\Rbac\Role;
use App\Support\Security\SecretRedactor;
use App\Support\Security\SensitiveFieldRegistry;
use App\Support\WhatsApp\WhatsAppConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiManager::class);

        // Secret redaction (Phase C0): one explicit registry + one redactor,
        // shared by the audit logger and the log channels.
        $this->app->singleton(SensitiveFieldRegistry::class);
        $this->app->singleton(SecretRedactor::class);

        // Where the AI router reads its model catalog from: the resolver picks
        // the database catalog (Phase B2) when it has enabled models, else the
        // config-backed bootstrap defaults — see config('ai.catalog_source').
        $this->app->bind(CatalogSource::class, CatalogSourceResolver::class);

        // The message pipeline resolves the agent through this binding. When AI
        // is enabled the real orchestrator answers, wrapped by the metering
        // decorator (subscription/usage enforcement — transparent when
        // billing.enforce is off). Otherwise the deterministic placeholder keeps
        // the pipeline working (local/testing, or before a key is configured).
        // `ai.enabled` is an emergency setting: AI_ENABLED in the environment
        // wins, else the database value, else the config default.
        $this->app->bind(AgentOrchestrator::class, static function (Application $app): AgentOrchestrator {
            if (! $app->make(SettingsRepository::class)->get('ai.enabled')) {
                return $app->make(PlaceholderAgentOrchestrator::class);
            }

            return new MeteredAgentOrchestrator(
                $app->make(AiAgentOrchestrator::class),
                $app->make(UsageEngine::class),
                $app->make(UsageLimitResponder::class),
                $app->make(UsageRecorder::class),
            );
        });

        $this->app->singleton(ChannelRegistry::class);

        // Resolved fresh each time so config overrides (tests, tenants) apply.
        $this->app->bind(WhatsAppConfig::class, static fn (): WhatsAppConfig => WhatsAppConfig::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // super_admin passes every Gate ability (policies included) without a
        // per-permission grant. Null = fall through to the normal checks.
        Gate::before(static function (User $user): ?bool {
            return $user->hasRole(Role::SuperAdmin->value) ? true : null;
        });
    }
}
