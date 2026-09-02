<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Public, unauthenticated health endpoint. No secrets are exposed.
|
*/

Route::get('/health', HealthController::class)->name('api.health');
