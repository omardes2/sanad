<?php

declare(strict_types=1);

use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    whatsappConfigure();
    // Isolate onboarding + inbound persistence from the outbound reply send.
    Queue::fake();
});

it('onboards an unknown valid WhatsApp sender as its own subscriber and channel account', function () {
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.a1', '970599000001', 'مرحبا', ['name' => 'أبو محمد']));

    $account = ChannelAccount::query()
        ->where('channel', ChannelType::WhatsApp)
        ->where('external_identifier', '+970599000001')
        ->first();

    expect($account)->not->toBeNull()
        ->and($account->status)->toBe(ChannelAccountStatus::Active)
        ->and($account->display_name)->toBe('أبو محمد');

    $subscriber = $account->user;
    expect($subscriber)->not->toBeNull()
        ->and($subscriber->is_admin)->toBeFalse()
        ->and($subscriber->phone)->toBe('+970599000001');

    // The inbound message is stored and linked to the subscriber (not an admin).
    expect(Message::where('direction', MessageDirection::Inbound)->count())->toBe(1)
        ->and(Message::first()->user_id)->toBe($subscriber->id);
});

it('reuses the same subscriber for repeated messages from the same number', function () {
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.b1', '970599000002', 'رسالة ١'));
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.b2', '970599000002', 'رسالة ٢'));

    expect(ChannelAccount::count())->toBe(1)
        ->and(User::query()->where('is_admin', false)->count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and(Message::where('direction', MessageDirection::Inbound)->count())->toBe(2);
});

it('matches an existing E.164 account even when the provider sends bare digits', function () {
    // Pre-existing subscriber stored in canonical E.164 form (with "+").
    $subscriber = User::factory()->create(['is_admin' => false, 'phone' => '+970599000003']);
    $account = ChannelAccount::factory()->for($subscriber)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => '+970599000003',
    ]);

    // Provider delivers the same number as bare digits (no "+").
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.c1', '970599000003', 'أهلا'));

    // No new subscriber or account — the bare-digit sender normalized to the
    // existing "+" account.
    expect(ChannelAccount::count())->toBe(1)
        ->and(User::count())->toBe(1)
        ->and(Message::where('direction', MessageDirection::Inbound)->count())->toBe(1)
        ->and(Message::first()->user_id)->toBe($subscriber->id)
        ->and(Message::first()->conversation->channel_account_id)->toBe($account->id);
});

it('never attaches a new WhatsApp sender to the admin operator', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    runWhatsAppWebhook(whatsappTextEnvelope('wamid.d1', '970599000004', 'test'));

    $message = Message::where('direction', MessageDirection::Inbound)->first();

    expect($message->user_id)->not->toBe($admin->id)
        ->and($admin->channelAccounts()->count())->toBe(0)
        ->and(User::find($message->user_id)->is_admin)->toBeFalse();
});

it('does not create duplicate subscriber, conversation, or message for a duplicate webhook', function () {
    // Same wamid delivered in two distinct envelopes (different envelope hash).
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.e1', '970599000005', 'مرحبا'));
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.e1', '970599000005', 'مرحبا'));

    expect(ChannelAccount::count())->toBe(1)
        ->and(User::query()->where('is_admin', false)->count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and(Message::where('direction', MessageDirection::Inbound)->count())->toBe(1);
});

it('skips onboarding for an invalid sender number', function () {
    // "12" is too short to be a valid E.164 number → adapter rejects it, so no
    // subscriber/account/message is created and the batch is not broken.
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.f1', '12', 'bad'));

    expect(ChannelAccount::count())->toBe(0)
        ->and(User::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});
