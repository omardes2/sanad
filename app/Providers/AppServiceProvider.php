<?php

namespace App\Providers;

use App\Agents\PlaceholderAgentOrchestrator;
use App\Channels\ChannelRegistry;
use App\Contracts\AgentOrchestrator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The message pipeline resolves the agent through this binding, so a
        // real AI orchestrator can replace the placeholder without touching
        // callers.
        $this->app->bind(AgentOrchestrator::class, PlaceholderAgentOrchestrator::class);

        $this->app->singleton(ChannelRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
