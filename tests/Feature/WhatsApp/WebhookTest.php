<?php

declare(strict_types=1);

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ---- GET verification ---------------------------------------------------

it('completes the verification handshake and echoes the challenge', function () {
    whatsappConfigure();

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=CHALLENGE-123')
        ->assertOk()
        ->assertSee('CHALLENGE-123');
});

it('rejects verification with a wrong verify token', function () {
    whatsappConfigure();

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=WRONG&hub.challenge=X')
        ->assertForbidden();
});

it('rejects verification with missing parameters', function () {
    whatsappConfigure();

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=X')->assertForbidden();
    $this->get('/webhooks/whatsapp?hub.verify_token=test-verify-token')->assertForbidden();
});

it('rejects verification when hub.mode is not subscribe even with a correct token', function () {
    whatsappConfigure();

    // A correct verify token must NOT be enough: the challenge is only echoed
    // when the mode is exactly "subscribe".
    $this->get('/webhooks/whatsapp?hub.mode=unsubscribe&hub.verify_token=test-verify-token&hub.challenge=CHALLENGE-123')
        ->assertForbidden()
        ->assertDontSee('CHALLENGE-123');

    $this->get('/webhooks/whatsapp?hub.verify_token=test-verify-token&hub.challenge=CHALLENGE-123')
        ->assertForbidden()
        ->assertDontSee('CHALLENGE-123');
});

it('fails closed on verification when the integration is disabled or unconfigured', function () {
    whatsappConfigure(['whatsapp.enabled' => false]);
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=X')
        ->assertForbidden();

    whatsappConfigure(['whatsapp.verify_token' => null]);
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=x&hub.challenge=X')
        ->assertForbidden();
});

// ---- POST event delivery ------------------------------------------------

it('accepts a correctly signed event: stores one WebhookEvent and queues one job', function () {
    Queue::fake();
    whatsappConfigure();

    postWhatsAppEnvelope(whatsappTextEnvelope('wamid.1', '970599000001', 'مرحبا'))
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect(WebhookEvent::where('provider', 'whatsapp')->count())->toBe(1)
        ->and(WebhookEvent::first()->status)->toBe(WebhookEventStatus::Received);

    Queue::assertPushed(ProcessWhatsAppWebhook::class, 1);
    Queue::assertPushed(
        ProcessWhatsAppWebhook::class,
        fn (ProcessWhatsAppWebhook $job) => $job->queue === 'webhooks',
    );
});

it('rejects an invalid signature with 403 and no side effects', function () {
    Queue::fake();
    whatsappConfigure();

    $raw = json_encode(whatsappTextEnvelope('wamid.1', '970599000001', 'hi'));
    postWhatsAppRaw($raw, 'sha256='.hash_hmac('sha256', $raw, 'WRONG-SECRET'))
        ->assertForbidden();

    expect(WebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a missing signature with 403 and no side effects', function () {
    Queue::fake();
    whatsappConfigure();

    $raw = json_encode(whatsappTextEnvelope('wamid.1', '970599000001', 'hi'));
    postWhatsAppRaw($raw, null)->assertForbidden();

    expect(WebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns 400 for invalid JSON with a valid signature', function () {
    Queue::fake();
    whatsappConfigure();

    postWhatsAppRaw('{not valid json', whatsappSignature('{not valid json'))
        ->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('is idempotent for a duplicate webhook redelivery: one event, one job', function () {
    Queue::fake();
    whatsappConfigure();

    $envelope = whatsappTextEnvelope('wamid.1', '970599000001', 'مرحبا');
    postWhatsAppEnvelope($envelope)->assertOk();
    postWhatsAppEnvelope($envelope)->assertOk(); // exact same bytes

    expect(WebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessWhatsAppWebhook::class, 1);
});

it('fails closed on POST when disabled or app secret missing', function () {
    Queue::fake();

    whatsappConfigure(['whatsapp.enabled' => false]);
    $raw = json_encode(whatsappTextEnvelope('wamid.1', '970599000001', 'hi'));
    postWhatsAppRaw($raw, whatsappSignature($raw))->assertForbidden();

    whatsappConfigure(['whatsapp.app_secret' => null]);
    postWhatsAppRaw($raw, 'sha256=deadbeef')->assertForbidden();

    expect(WebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});
