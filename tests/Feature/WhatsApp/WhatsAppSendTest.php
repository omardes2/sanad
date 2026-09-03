<?php

declare(strict_types=1);

use App\Channels\WhatsAppChannelAdapter;
use App\Data\ChannelDeliveryResult;
use App\Data\InboundMessageData;
use App\Data\OutboundMessageData;
use App\Enums\ChannelType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageType;
use App\Exceptions\WhatsAppSendException;
use App\Models\Message;
use App\Services\MessageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => whatsappConfigure());

function sendWhatsApp(string $to = '+970599000001', string $text = 'hello'): ChannelDeliveryResult
{
    return app(WhatsAppChannelAdapter::class)->send(
        new OutboundMessageData(ChannelType::WhatsApp, $to, MessageType::Text, $text)
    );
}

it('sends to the correct URL with bearer auth and a valid text payload', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out.1']]], 200)]);

    $result = sendWhatsApp('+970599000001', 'hello');

    expect($result->status)->toBe(MessageDeliveryStatus::Accepted)
        ->and($result->providerMessageId)->toBe('wamid.out.1');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v21.0/PNID_123/messages'
            && $request->hasHeader('Authorization', 'Bearer TEST_ACCESS_TOKEN')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '970599000001' // recipient carries no "+"
            && $request['type'] === 'text'
            && $request['text']['body'] === 'hello';
    });
});

it('retries on HTTP 500 then succeeds', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'boom'], 500)
        ->push(['messages' => [['id' => 'wamid.ok']]], 200),
    ]);

    expect(sendWhatsApp()->providerMessageId)->toBe('wamid.ok');
    Http::assertSentCount(2);
});

it('retries on HTTP 429 then succeeds', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'rate'], 429)
        ->push(['messages' => [['id' => 'wamid.ok']]], 200),
    ]);

    expect(sendWhatsApp()->providerMessageId)->toBe('wamid.ok');
    Http::assertSentCount(2);
});

it('retries on a network error then succeeds', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw new ConnectionException('timeout');
        }

        return Http::response(['messages' => [['id' => 'wamid.net']]], 200);
    });

    expect(sendWhatsApp()->providerMessageId)->toBe('wamid.net');
    expect($calls)->toBe(2);
});

it('does NOT retry on a non-transient 4xx and throws a safe exception', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'invalid']], 400)]);

    expect(fn () => sendWhatsApp())->toThrow(WhatsAppSendException::class);
    Http::assertSentCount(1);
});

it('gives up after exhausting retries on persistent 5xx', function () {
    Http::fake(['*' => Http::response(['error' => 'down'], 503)]);

    expect(fn () => sendWhatsApp())->toThrow(WhatsAppSendException::class);
    Http::assertSentCount(3);
});

it('never leaks the token, recipient number or message body in a send exception', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'invalid']], 400)]);

    try {
        sendWhatsApp('+970599000001', 'my secret message body');
        $this->fail('expected exception');
    } catch (WhatsAppSendException $e) {
        expect($e->getMessage())
            ->not->toContain('TEST_ACCESS_TOKEN')
            ->not->toContain('970599000001')
            ->not->toContain('my secret message body');
    }
});

it('delivers a reply end to end through the job and records the provider message id', function () {
    Queue::fake(); // capture the inbound (messages) dispatch, run it manually
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]], 200)]);

    whatsappAccount('+970599000001');

    $inbound = app(MessageProcessor::class)->process(new InboundMessageData(
        channel: ChannelType::WhatsApp,
        externalMessageId: 'wamid.in',
        externalUserId: '+970599000001',
        type: MessageType::Text,
        text: 'مرحبا',
    ))->message;

    pipelineRunJob($inbound->id);

    $reply = Message::where('in_reply_to_message_id', $inbound->id)->first();
    expect($reply)->not->toBeNull()
        ->and($reply->provider_message_id)->toBe('wamid.reply')
        ->and($reply->delivery_status)->toBe(MessageDeliveryStatus::Accepted);
});
