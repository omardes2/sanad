<?php

namespace App\Providers;

use App\Agents\AiAgentOrchestrator;
use App\Agents\PlaceholderAgentOrchestrator;
use App\Channels\ChannelRegistry;
use App\Contracts\AgentOrchestrator;
use App\Services\Ai\AiManager;
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

        // The message pipeline resolves the agent through this binding. When AI
        // is enabled the real orchestrator answers; otherwise the deterministic
        // placeholder keeps the pipeline working (local/testing, or before a
        // provider key is configured). Callers are untouched either way.
        $this->app->bind(AgentOrchestrator::class, static function (Application $app): AgentOrchestrator {
            return config('ai.enabled')
                ? $app->make(AiAgentOrchestrator::class)
                : $app->make(PlaceholderAgentOrchestrator::class);
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
