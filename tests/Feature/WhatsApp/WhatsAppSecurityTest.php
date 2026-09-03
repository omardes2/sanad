<?php

declare(strict_types=1);

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WebhookEvent;
use App\Support\SafeError;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;

uses(RefreshDatabase::class);

/**
 * Capture everything written to the log during $callback and return it as one
 * flat string (message + context).
 */
function captureLogs(Closure $callback): string
{
    $handler = new TestHandler;
    Log::driver()->getLogger()->pushHandler($handler);

    try {
        $callback();
    } finally {
        // leave the handler; the logger is rebuilt per test
    }

    return collect($handler->getRecords())
        ->map(fn ($record) => $record['message'].' '.json_encode($record['context'], JSON_UNESCAPED_UNICODE))
        ->implode("\n");
}

it('does not log the message body or full phone number when processing a webhook', function () {
    Queue::fake(); // do not run the downstream reply send in this log test
    whatsappConfigure();
    whatsappAccount('+970599000001');

    $secretBody = 'TOP-SECRET-BODY-DO-NOT-LOG';

    $logs = captureLogs(function () use ($secretBody) {
        $event = WebhookEvent::create([
            'provider' => 'whatsapp',
            'external_event_id' => 'evt-1',
            'payload' => whatsappTextEnvelope('wamid.1', '970599000001', $secretBody),
            'status' => WebhookEventStatus::Received,
            'received_at' => now(),
        ]);
        app()->call([new ProcessWhatsAppWebhook($event->id), 'handle']);
    });

    expect($logs)->not->toContain($secretBody)
        ->not->toContain('970599000001')   // full phone must never appear
        ->not->toContain('+970599000001');
});

it('keeps app secret and verify token out of logs on a bad signature', function () {
    whatsappConfigure();

    $logs = captureLogs(function () {
        $raw = json_encode(whatsappTextEnvelope('wamid.1', '970599000001', 'hi'));
        postWhatsAppRaw($raw, 'sha256='.hash_hmac('sha256', $raw, 'WRONG'));
    });

    expect($logs)->not->toContain('test-app-secret')
        ->not->toContain('test-verify-token');
});

it('stores only a class-name error for a non-authored exception (no leaked message)', function () {
    whatsappConfigure();
    $event = WebhookEvent::create([
        'provider' => 'whatsapp',
        'external_event_id' => 'evt-fail',
        'payload' => ['entry' => []],
        'status' => WebhookEventStatus::Received,
        'received_at' => now(),
    ]);

    // A third-party/framework exception may carry sensitive text — its message
    // must NOT be stored, only the class name.
    (new ProcessWhatsAppWebhook($event->id))->failed(
        new RuntimeException('boom with token TEST_ACCESS_TOKEN and +970599000001')
    );

    $error = (string) $event->fresh()->error_message;
    expect($event->fresh()->status)->toBe(WebhookEventStatus::Failed)
        ->and($error)->toBe('RuntimeException')
        ->and($error)->not->toContain('TEST_ACCESS_TOKEN')
        ->and($error)->not->toContain('970599000001');
});

it('does not embed database exception bindings (message text/phone) in a stored error', function () {
    $qe = new QueryException(
        'pgsql',
        'insert into "messages" ("text_content", "external_message_id") values (?, ?)',
        ['SECRET MESSAGE BODY', '+970599000001'],
        new Exception('constraint'),
    );

    $safe = SafeError::summarize($qe);

    expect($safe)->toBe('QueryException')
        ->and($safe)->not->toContain('SECRET MESSAGE BODY')
        ->and($safe)->not->toContain('970599000001');
});
