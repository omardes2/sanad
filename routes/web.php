<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Middleware\EnsureDevEnvironment;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard\Conversations;
use App\Livewire\Dashboard\Expenses;
use App\Livewire\Dashboard\Messages;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Dashboard\Reminders;
use App\Livewire\Dashboard\Tasks;
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
    });

// Local chat simulator — 404 outside local/testing (see EnsureDevEnvironment).
Route::get('/dev/chat', Chat::class)
    ->middleware(EnsureDevEnvironment::class)
    ->name('dev.chat');
