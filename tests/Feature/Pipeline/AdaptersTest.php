<?php

declare(strict_types=1);

use App\Channels\WebSimulatorChannelAdapter;
use App\Channels\WhatsAppChannelAdapter;
use App\Data\OutboundMessageData;
use App\Enums\ChannelType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageType;
use App\Exceptions\WhatsAppConfigurationException;

it('web simulator normalizes a payload into an InboundMessageData', function () {
    $dto = (new WebSimulatorChannelAdapter)->toInbound([
        'external_message_id' => 'web-1',
        'external_user_id' => 'web-user-1',
        'text' => 'مرحبا',
    ]);

    expect($dto->channel)->toBe(ChannelType::Web)
        ->and($dto->externalMessageId)->toBe('web-1')
        ->and($dto->externalUserId)->toBe('web-user-1')
        ->and($dto->type)->toBe(MessageType::Text)
        ->and($dto->text)->toBe('مرحبا');
});

it('web simulator send reports the reply as sent', function () {
    $result = (new WebSimulatorChannelAdapter)->send(
        new OutboundMessageData(ChannelType::Web, 'web-user-1', MessageType::Text, 'hi')
    );

    expect($result->status)->toBe(MessageDeliveryStatus::Sent)
        ->and($result->providerMessageId)->toBeNull();
});

it('whatsapp adapter normalizes a text message with E.164 sender and profile name', function () {
    $dto = app(WhatsAppChannelAdapter::class)->toInbound([
        'message' => [
            'id' => 'wamid.ABC',
            'from' => '970599000001',
            'type' => 'text',
            'timestamp' => '1757000000',
            'text' => ['body' => 'مرحبا'],
        ],
        'contacts' => [['wa_id' => '970599000001', 'profile' => ['name' => 'عمر']]],
        'metadata' => ['phone_number_id' => 'PNID'],
        'waba_id' => 'WABA',
    ]);

    expect($dto->channel)->toBe(ChannelType::WhatsApp)
        ->and($dto->externalMessageId)->toBe('wamid.ABC')
        ->and($dto->externalUserId)->toBe('+970599000001')
        ->and($dto->type)->toBe(MessageType::Text)
        ->and($dto->text)->toBe('مرحبا')
        ->and($dto->metadata['profile_name'])->toBe('عمر')
        ->and($dto->metadata['phone_number_id'])->toBe('PNID')
        ->and($dto->metadata['waba_id'])->toBe('WABA');
});

it('whatsapp adapter rejects an invalid sender number', function () {
    expect(fn () => app(WhatsAppChannelAdapter::class)->toInbound([
        'message' => ['id' => 'wamid.X', 'from' => 'not-a-number', 'type' => 'text', 'text' => ['body' => 'hi']],
    ]))->toThrow(InvalidArgumentException::class);
});

it('whatsapp adapter fails closed when the integration is disabled', function () {
    config(['whatsapp.enabled' => false]);

    expect(fn () => app(WhatsAppChannelAdapter::class)->send(
        new OutboundMessageData(ChannelType::WhatsApp, '+970599000001', MessageType::Text, 'hi')
    ))->toThrow(WhatsAppConfigurationException::class);
});
