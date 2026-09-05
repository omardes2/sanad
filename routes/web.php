<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Dashboard\ProviderCredentialController;
use App\Http\Controllers\Dashboard\UsageExportController;
use App\Http\Middleware\EnsureDevEnvironment;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard\Ai\Health as AiHealth;
use App\Livewire\Dashboard\Ai\Models as AiModels;
use App\Livewire\Dashboard\Ai\Pricing as AiPricing;
use App\Livewire\Dashboard\Ai\Providers as AiProviders;
use App\Livewire\Dashboard\Ai\Routing as AiRouting;
use App\Livewire\Dashboard\AuditLogs;
use App\Livewire\Dashboard\Conversations;
use App\Livewire\Dashboard\Expenses;
use App\Livewire\Dashboard\Messages;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Dashboard\Persona;
use App\Livewire\Dashboard\Plans;
use App\Livewire\Dashboard\Reminders;
use App\Livewire\Dashboard\Settings;
use App\Livewire\Dashboard\SubscriberDetail;
use App\Livewire\Dashboard\Subscribers;
use App\Livewire\Dashboard\Tasks;
use App\Livewire\Dashboard\Usage;
use App\Livewire\Dashboard\WhatsAppStatus;
use App\Livewire\Dev\Chat;
use App\Livewire\HomePage;
use Illuminate\Support\Facades\Route;

Route::get('/', HomePage::class)->name('home');

// ---- Authentication (no public registration) ----------------------------
// Accounts are created only via the `sanad:make-admin` console command.
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::post('/logout', LogoutController::class)
    ->middleware('auth')
    ->name('logout');

// ---- Operator dashboard -------------------------------------------------
// Every page requires an authenticated admin (auth first, then the admin
// gate) — no dashboard route is reachable without both.
Route::middleware(['auth', 'admin'])
    ->prefix('dashboard')
    ->group(function () {
        Route::get('/', Overview::class)->name('dashboard');
        Route::get('/conversations', Conversations::class)->name('dashboard.conversations');
        Route::get('/messages', Messages::class)->name('dashboard.messages');
        Route::get('/tasks', Tasks::class)->name('dashboard.tasks');
        Route::get('/reminders', Reminders::class)->name('dashboard.reminders');
        Route::get('/expenses', Expenses::class)->name('dashboard.expenses');
        Route::get('/whatsapp', WhatsAppStatus::class)->name('dashboard.whatsapp');

        // Subscriptions, plans & usage. These pages pre-date RBAC: a legacy
        // is_admin account keeps them, role accounts need the permission.
        Route::get('/plans', Plans::class)->middleware('permission.legacy:plans.manage')->name('dashboard.plans');
        Route::get('/subscribers', Subscribers::class)->middleware('permission.legacy:subscribers.view')->name('dashboard.subscribers');
        Route::get('/subscribers/{subscriber}', SubscriberDetail::class)->middleware('permission.legacy:subscribers.view')->name('dashboard.subscribers.show');

        // Audit trail (Phase C0): strict RBAC — no legacy bypass, fail closed.
        Route::get('/audit', AuditLogs::class)->middleware('permission:audit.view')->name('dashboard.audit');

        // App settings + persona/prompts (Phase C1): strict RBAC; each setting
        // additionally enforces its own permission in SettingsRepository.
        Route::get('/settings', Settings::class)->middleware('permission:settings.manage')->name('dashboard.settings');
        Route::get('/persona', Persona::class)->middleware('permission:persona.manage')->name('dashboard.persona');

        // AI catalog + usage (Phase C2): strict RBAC on every route; each
        // write additionally re-checks its permission in CatalogAdmin /
        // PriceBook callers. No credentials, no test connection, no cutover.
        Route::get('/ai/providers', AiProviders::class)->middleware('permission:ai.providers.view')->name('dashboard.ai.providers');
        Route::get('/ai/models', AiModels::class)->middleware('permission:ai.models.manage')->name('dashboard.ai.models');
        Route::get('/ai/pricing', AiPricing::class)->middleware('permission:ai.pricing.view')->name('dashboard.ai.pricing');
        Route::get('/ai/routing', AiRouting::class)->middleware('permission:ai.routing.manage')->name('dashboard.ai.routing');
        // Credentials + provider health (Phase C3): the secret is posted ONCE
        // through a plain form (write-only); lifecycle in CredentialManager.
        Route::post('/ai/providers/{provider}/credentials', [ProviderCredentialController::class, 'store'])->middleware('permission:ai.credentials.manage')->name('dashboard.ai.credentials.store');
        Route::post('/ai/credentials/{credential}/activate', [ProviderCredentialController::class, 'activate'])->middleware('permission:ai.credentials.manage')->name('dashboard.ai.credentials.activate');
        Route::post('/ai/credentials/{credential}/activate-unverified', [ProviderCredentialController::class, 'activateUnverified'])->middleware('permission:ai.credentials.manage')->name('dashboard.ai.credentials.activate_unverified');
        Route::post('/ai/credentials/{credential}/revoke', [ProviderCredentialController::class, 'revoke'])->middleware('permission:ai.credentials.manage')->name('dashboard.ai.credentials.revoke');
        Route::get('/ai/health', AiHealth::class)->middleware('permission:ai.health.view')->name('dashboard.ai.health');

        Route::get('/usage', Usage::class)->middleware('permission:usage.view')->name('dashboard.usage');
        Route::get('/usage/export', UsageExportController::class)->middleware('permission:usage.export')->name('dashboard.usage.export');
    });

// Local chat simulator — 404 outside local/testing (see EnsureDevEnvironment).
Route::get('/dev/chat', Chat::class)
    ->middleware(EnsureDevEnvironment::class)
    ->name('dev.chat');
