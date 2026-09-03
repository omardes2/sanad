<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Public, unauthenticated provider webhooks. Registered with NO middleware
| group (no CSRF, no session): identity is established by the verify token
| (GET) and the HMAC signature (POST) inside the controller.
|
*/

Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
    ->name('webhooks.whatsapp.verify');

Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])
    ->name('webhooks.whatsapp.handle');
