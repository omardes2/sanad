<?php

declare(strict_types=1);

use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessInboundMessage;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Message;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Store an envelope as a WebhookEvent and run the processing job synchronously.
 */
function runWhatsAppWebhook(array $envelope): WebhookEvent
{
    $event = WebhookEvent::create([
        'provider' => 'whatsapp',
        'external_event_id' => hash('sha256', json_encode($envelope).uniqid('', true)),
        'payload' => $envelope,
        'status' => WebhookEventStatus::Received,
        'received_at' => now(),
    ]);

    app()->call([new ProcessWhatsAppWebhook($event->id), 'handle']);

    return $event->fresh();
}

beforeEach(function () {
    whatsappConfigure();
    Queue::fake(); // isolate the inbound (messages) pipeline dispatch
});

it('converts a text webhook into a stored inbound message', function () {
    whatsappAccount('+970599000001');

    $event = runWhatsAppWebhook(whatsappTextEnvelope('wamid.1', '970599000001', 'مرحبا'));

    $inbound = Message::where('direction', MessageDirection::Inbound)->first();
    expect($event->status)->toBe(WebhookEventStatus::Processed)
        ->and($inbound)->not->toBeNull()
        ->and($inbound->external_message_id)->toBe('wamid.1')
        ->and($inbound->text_content)->toBe('مرحبا')
        ->and($inbound->metadata['profile_name'])->toBe('Tester');

    Queue::assertPushed(ProcessInboundMessage::class, 1);
});

it('normalizes the sender number to E.164 on the channel account lookup', function () {
    $account = whatsappAccount('+970599000001');

    runWhatsAppWebhook(whatsappTextEnvelope('wamid.1', '970599000001', 'hi'));

    $inbound = Message::where('direction', MessageDirection::Inbound)->first();
    expect($inbound)->not->toBeNull()
        ->and($inbound->conversation->channel_account_id)->toBe($account->id);
});

it('processes every entry, change and message in a batch (not just the first)', function () {
    whatsappAccount('+970599000001');
    whatsappAccount('+15550001234');

    $envelope = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            whatsappTextEnvelope('wamid.A', '970599000001', 'first')['entry'][0],
            whatsappTextEnvelope('wamid.B', '15550001234', 'second')['entry'][0],
        ],
    ];
    // Add a second message inside the first change too.
    $envelope['entry'][0]['changes'][0]['value']['messages'][] = [
        'from' => '970599000001', 'id' => 'wamid.C', 'timestamp' => '1757000001',
        'type' => 'text', 'text' => ['body' => 'third'],
    ];

    runWhatsAppWebhook($envelope);

    expect(Message::where('direction', MessageDirection::Inbound)->pluck('external_message_id')->sort()->values()->all())
        ->toBe(['wamid.A', 'wamid.B', 'wamid.C']);
});

it('ignores events for a non-matching phone number id or WABA', function () {
    whatsappAccount('+970599000001');

    runWhatsAppWebhook(whatsappTextEnvelope('wamid.1', '970599000001', 'hi', ['phone_number_id' => 'OTHER_PNID']));
    expect(Message::count())->toBe(0);

    runWhatsAppWebhook(whatsappTextEnvelope('wamid.2', '970599000001', 'hi', ['waba_id' => 'OTHER_WABA']));
    expect(Message::count())->toBe(0);
});

it('acknowledges an unsupported (media) message without creating a reply', function () {
    whatsappAccount('+970599000001');

    $envelope = whatsappTextEnvelope('wamid.img', '970599000001', '', ['type' => 'image']);
    // Replace the text body with an image object.
    unset($envelope['entry'][0]['changes'][0]['value']['messages'][0]['text']);
    $envelope['entry'][0]['changes'][0]['value']['messages'][0]['image'] = ['id' => 'media-1', 'mime_type' => 'image/jpeg'];

    $event = runWhatsAppWebhook($envelope);

    expect($event->status)->toBe(WebhookEventStatus::Processed)
        ->and(Message::count())->toBe(0);
    Queue::assertNotPushed(ProcessInboundMessage::class);
});

it('is idempotent per WhatsApp message id across different envelopes', function () {
    whatsappAccount('+970599000001');

    // Same wamid, two DIFFERENT envelopes (different bodies → different SHA-256
    // envelope hash, so both are stored/processed as distinct webhook events).
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.dup', '970599000001', 'hi'));
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.dup', '970599000001', 'hi again'));

    // Message-level idempotency (the wamid) yields exactly one inbound and one
    // logical dispatch of the reply pipeline, and never a duplicate reply.
    expect(Message::where('external_message_id', 'wamid.dup')->count())->toBe(1);
    Queue::assertPushed(ProcessInboundMessage::class, 1);
});

it('skips a single structurally corrupt message without dropping valid ones', function () {
    whatsappAccount('+970599000001');
    whatsappAccount('+15550001234');

    $envelope = whatsappTextEnvelope('wamid.valid1', '970599000001', 'valid one');
    // Inject a corrupt (non-array) message element, and a second valid message.
    $envelope['entry'][0]['changes'][0]['value']['messages'][] = 'CORRUPT-NOT-AN-ARRAY';
    $envelope['entry'][0]['changes'][0]['value']['messages'][] = [
        'from' => '15550001234', 'id' => 'wamid.valid2', 'timestamp' => '1757000002',
        'type' => 'text', 'text' => ['body' => 'valid two'],
    ];

    $event = runWhatsAppWebhook($envelope);

    expect($event->status)->toBe(WebhookEventStatus::Processed)
        ->and(Message::where('direction', MessageDirection::Inbound)->pluck('external_message_id')->sort()->values()->all())
        ->toBe(['wamid.valid1', 'wamid.valid2']);
});

it('skips a message with an invalid sender but processes valid ones in the batch', function () {
    whatsappAccount('+970599000001');

    $envelope = whatsappTextEnvelope('wamid.bad', 'not-a-number', 'bad sender');
    $envelope['entry'][0]['changes'][0]['value']['messages'][] = [
        'from' => '970599000001', 'id' => 'wamid.good', 'timestamp' => '1757000003',
        'type' => 'text', 'text' => ['body' => 'good'],
    ];

    runWhatsAppWebhook($envelope);

    expect(Message::where('direction', MessageDirection::Inbound)->pluck('external_message_id')->all())
        ->toBe(['wamid.good']);
});

// ---- Delivery status webhooks ------------------------------------------

it('advances delivery status sent → delivered → read without moving backwards', function () {
    $outbound = Message::factory()->outbound()->create([
        'provider_message_id' => 'wamid.out',
        'delivery_status' => MessageDeliveryStatus::Accepted,
    ]);

    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.out', 'sent'));
    expect($outbound->fresh()->delivery_status)->toBe(MessageDeliveryStatus::Sent);

    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.out', 'delivered'));
    expect($outbound->fresh()->delivery_status)->toBe(MessageDeliveryStatus::Delivered);

    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.out', 'read'));
    $fresh = $outbound->fresh();
    expect($fresh->delivery_status)->toBe(MessageDeliveryStatus::Read)
        ->and($fresh->sent_at)->not->toBeNull()
        ->and($fresh->delivered_at)->not->toBeNull()
        ->and($fresh->read_at)->not->toBeNull();

    // A late "sent" must not regress a read message.
    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.out', 'sent'));
    expect($outbound->fresh()->delivery_status)->toBe(MessageDeliveryStatus::Read);
});

it('treats a duplicate status as idempotent and never lets failed override delivered', function () {
    $outbound = Message::factory()->outbound()->create([
        'provider_message_id' => 'wamid.out',
        'delivery_status' => MessageDeliveryStatus::Delivered,
        'delivered_at' => now(),
    ]);

    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.out', 'delivered')); // duplicate
    expect($outbound->fresh()->delivery_status)->toBe(MessageDeliveryStatus::Delivered);

    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.out', 'failed', ['error_code' => 131047]));
    $fresh = $outbound->fresh();
    expect($fresh->delivery_status)->toBe(MessageDeliveryStatus::Delivered)
        ->and($fresh->delivery_error_code)->toBeNull();
});

it('records a safe error code when a message fails before delivery', function () {
    $outbound = Message::factory()->outbound()->create([
        'provider_message_id' => 'wamid.out',
        'delivery_status' => MessageDeliveryStatus::Sent,
        'sent_at' => now(),
    ]);

    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.out', 'failed', ['error_code' => 131047]));
    $fresh = $outbound->fresh();
    expect($fresh->delivery_status)->toBe(MessageDeliveryStatus::Failed)
        ->and($fresh->delivery_error_code)->toBe('131047');
});

it('safely ignores a status for an unknown message without breaking the batch', function () {
    $known = Message::factory()->outbound()->create([
        'provider_message_id' => 'wamid.known',
        'delivery_status' => MessageDeliveryStatus::Accepted,
    ]);

    $envelope = whatsappStatusEnvelope('wamid.unknown', 'delivered');
    // Append a status for a known message in the same batch.
    $envelope['entry'][0]['changes'][0]['value']['statuses'][] = [
        'id' => 'wamid.known', 'status' => 'sent', 'timestamp' => '1757000200', 'recipient_id' => '970599000001',
    ];

    $event = runWhatsAppWebhook($envelope);

    expect($event->status)->toBe(WebhookEventStatus::Processed)
        ->and($known->fresh()->delivery_status)->toBe(MessageDeliveryStatus::Sent);
});

it('does not regress or clear timestamps when statuses arrive out of order', function () {
    $outbound = Message::factory()->outbound()->create([
        'provider_message_id' => 'wamid.ooo',
        'delivery_status' => MessageDeliveryStatus::Accepted,
    ]);

    // Reach "read" (all three timestamps set).
    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.ooo', 'sent'));
    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.ooo', 'delivered'));
    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.ooo', 'read'));

    $afterRead = $outbound->fresh();
    $sentAt = $afterRead->sent_at;
    $deliveredAt = $afterRead->delivered_at;
    $readAt = $afterRead->read_at;

    // Late, out-of-order events must not change status or clear timestamps.
    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.ooo', 'delivered'));
    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.ooo', 'sent'));
    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.ooo', 'read')); // duplicate read

    $final = $outbound->fresh();
    expect($final->delivery_status)->toBe(MessageDeliveryStatus::Read)
        ->and($final->sent_at->equalTo($sentAt))->toBeTrue()
        ->and($final->delivered_at->equalTo($deliveredAt))->toBeTrue()
        ->and($final->read_at->equalTo($readAt))->toBeTrue()
        ->and($final->delivery_error_code)->toBeNull();
});

it('keeps delivered when a later sent arrives (no backward move)', function () {
    $outbound = Message::factory()->outbound()->create([
        'provider_message_id' => 'wamid.d',
        'delivery_status' => MessageDeliveryStatus::Delivered,
        'delivered_at' => now()->subMinute(),
    ]);
    $deliveredAt = $outbound->delivered_at;

    runWhatsAppWebhook(whatsappStatusEnvelope('wamid.d', 'sent'));

    $fresh = $outbound->fresh();
    expect($fresh->delivery_status)->toBe(MessageDeliveryStatus::Delivered)
        ->and($fresh->sent_at)->toBeNull()                 // late sent did not stamp
        ->and($fresh->delivered_at->equalTo($deliveredAt))->toBeTrue();
});
