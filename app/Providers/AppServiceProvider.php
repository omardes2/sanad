<?php

namespace App\Providers;

use App\Agents\AiAgentOrchestrator;
use App\Agents\MeteredAgentOrchestrator;
use App\Agents\PlaceholderAgentOrchestrator;
use App\Channels\ChannelRegistry;
use App\Contracts\AgentOrchestrator;
use App\Contracts\Ai\CatalogSource;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\ConfigCatalogSource;
use App\Services\Billing\UsageEngine;
use App\Services\Billing\UsageLimitResponder;
use App\Services\Billing\UsageRecorder;
use App\Support\WhatsApp\WhatsAppConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiManager::class);

        // Where the AI router reads its model catalog from. Config-backed
        // bootstrap defaults now; a database-backed source managed from Sanad
        // Admin replaces this binding later without touching the router.
        $this->app->bind(CatalogSource::class, ConfigCatalogSource::class);

        // The message pipeline resolves the agent through this binding. When AI
        // is enabled the real orchestrator answers, wrapped by the metering
        // decorator (subscription/usage enforcement — transparent when
        // billing.enforce is off). Otherwise the deterministic placeholder keeps
        // the pipeline working (local/testing, or before a key is configured).
        $this->app->bind(AgentOrchestrator::class, static function (Application $app): AgentOrchestrator {
            if (! config('ai.enabled')) {
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
        //
    }
}
