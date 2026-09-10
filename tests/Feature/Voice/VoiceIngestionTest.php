<?php

declare(strict_types=1);

use App\Enums\MessageType;
use App\Enums\TranscriptionStatus;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessInboundMessage;
use App\Jobs\TranscribeVoiceNote;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * INGESTION: a voice note becomes a real message before anything is decided
 * about it.
 *
 * This is the behaviour change at the heart of the phase. Before it,
 * ProcessWhatsAppWebhook returned early on any non-text message — the audio was
 * dropped before a row existed, so nothing downstream could report what became
 * of it, not even to say it was refused. Storing it first is what makes every
 * later outcome, including "no", something the subscriber can be told.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    whatsappConfigure();
    Queue::fake();
});

it('stores an inbound voice note as one audio message pending transcription', function () {
    $account = whatsappAccount('+970599000001');

    $event = runWhatsAppWebhook(whatsappVoiceEnvelope('wamid.v1', '970599000001', [
        'media_id' => 'media-abc',
        'mime_type' => 'audio/ogg; codecs=opus',
    ]));

    $message = Message::query()->firstWhere('external_message_id', 'wamid.v1');

    expect($event->status)->toBe(WebhookEventStatus::Processed)
        ->and(Message::count())->toBe(1)
        ->and($message)->not->toBeNull()
        ->and($message->user_id)->toBe($account->user_id)
        ->and($message->type)->toBe(MessageType::Audio)
        ->and($message->voice_media_id)->toBe('media-abc')
        ->and($message->voice_mime_type)->toBe('audio/ogg; codecs=opus')
        ->and($message->transcription_status)->toBe(TranscriptionStatus::Pending)
        ->and($message->transcription_attempts)->toBe(0)
        // No text yet: the transcript IS this message's text, and it does not
        // exist until the provider produces it.
        ->and($message->text_content)->toBeNull()
        // The audio was never downloaded, so nothing points at a file.
        ->and($message->media_path)->toBeNull()
        // WhatsApp's own fact, carried through untouched.
        ->and($message->metadata['voice'])->toBeTrue();
});

it('sends a voice note to transcription and NOT straight to the reply path', function () {
    whatsappAccount('+970599000001');

    runWhatsAppWebhook(whatsappVoiceEnvelope('wamid.v2', '970599000001'));

    // The reply must wait for words. Answering an empty message would be
    // answering something the subscriber never said.
    Queue::assertPushed(TranscribeVoiceNote::class, 1);
    Queue::assertNotPushed(ProcessInboundMessage::class);
});

it('still sends a text message straight to the reply path', function () {
    whatsappAccount('+970599000001');

    runWhatsAppWebhook(whatsappTextEnvelope('wamid.t1', '970599000001', 'مرحبا'));

    Queue::assertPushed(ProcessInboundMessage::class, 1);
    Queue::assertNotPushed(TranscribeVoiceNote::class);
});

it('creates exactly one audio message when the same voice note arrives twice', function () {
    whatsappAccount('+970599000001');

    // Two DIFFERENT envelopes carrying the SAME wamid — a real redelivery, not
    // the same envelope replayed (which the webhook short-circuits earlier).
    runWhatsAppWebhook(whatsappVoiceEnvelope('wamid.dup', '970599000001', ['media_id' => 'media-1']));
    runWhatsAppWebhook(whatsappVoiceEnvelope('wamid.dup', '970599000001', ['media_id' => 'media-2']));

    expect(Message::query()->where('external_message_id', 'wamid.dup')->count())->toBe(1)
        ->and(Message::count())->toBe(1);

    // And exactly one transcription: a duplicate must not be able to buy a
    // second paid request by looking like new work.
    Queue::assertPushed(TranscribeVoiceNote::class, 1);
});

it('stores an audio message with no media reference without queueing work that cannot succeed', function () {
    whatsappAccount('+970599000001');

    runWhatsAppWebhook(whatsappVoiceEnvelope('wamid.noid', '970599000001', ['media_id' => '']));

    $message = Message::query()->firstWhere('external_message_id', 'wamid.noid');

    expect($message)->not->toBeNull()
        ->and($message->voice_media_id)->toBeNull()
        // Nothing to fetch ⇒ not pending. Marking it pending would queue work
        // that could never succeed and would retry forever.
        ->and($message->transcription_status)->toBeNull();

    Queue::assertNotPushed(TranscribeVoiceNote::class);
});

it('still ignores message types the pipeline cannot answer', function () {
    whatsappAccount('+970599000001');

    $envelope = whatsappTextEnvelope('wamid.img', '970599000001', '', ['type' => 'image']);
    unset($envelope['entry'][0]['changes'][0]['value']['messages'][0]['text']);
    $envelope['entry'][0]['changes'][0]['value']['messages'][0]['image'] = ['id' => 'media-1', 'mime_type' => 'image/jpeg'];

    $event = runWhatsAppWebhook($envelope);

    // Accepting a type nothing can answer would leave the subscriber waiting
    // for a reply that never comes. Voice is in scope; images are not, yet.
    expect($event->status)->toBe(WebhookEventStatus::Processed)
        ->and(Message::count())->toBe(0);
});

it('carries the voice flag through as false for an attached audio file', function () {
    whatsappAccount('+970599000001');

    runWhatsAppWebhook(whatsappVoiceEnvelope('wamid.file', '970599000001', ['voice' => false]));

    $message = Message::query()->firstWhere('external_message_id', 'wamid.file');

    // Stored and pending: WHETHER an attached file is refused is policy, and
    // policy is applied where every refusal shares one vocabulary of reasons.
    expect($message->metadata['voice'])->toBeFalse()
        ->and($message->transcription_status)->toBe(TranscriptionStatus::Pending);
});
