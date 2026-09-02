<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDevEnvironment;
use App\Livewire\Dev\Chat;
use App\Livewire\HomePage;
use Illuminate\Support\Facades\Route;

Route::get('/', HomePage::class)->name('home');

// Local chat simulator — 404 outside local/testing (see EnsureDevEnvironment).
Route::get('/dev/chat', Chat::class)
    ->middleware(EnsureDevEnvironment::class)
    ->name('dev.chat');
