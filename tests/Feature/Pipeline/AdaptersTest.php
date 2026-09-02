<?php

declare(strict_types=1);

use App\Channels\WebSimulatorChannelAdapter;
use App\Channels\WhatsAppChannelAdapter;
use App\Data\OutboundMessageData;
use App\Enums\ChannelType;
use App\Enums\MessageType;
use App\Exceptions\IntegrationDisabledException;

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

it('web simulator send is a no-op and never throws', function () {
    (new WebSimulatorChannelAdapter)->send(
        new OutboundMessageData(ChannelType::Web, 'web-user-1', MessageType::Text, 'hi')
    );

    expect(true)->toBeTrue();
});

it('whatsapp adapter throws when attempting a real send (no Meta connection)', function () {
    $adapter = new WhatsAppChannelAdapter;

    expect(fn () => $adapter->send(
        new OutboundMessageData(ChannelType::WhatsApp, '970599000001', MessageType::Text, 'hi')
    ))->toThrow(IntegrationDisabledException::class);
});

it('whatsapp adapter throws on inbound normalization (skeleton only)', function () {
    expect(fn () => (new WhatsAppChannelAdapter)->toInbound([]))
        ->toThrow(IntegrationDisabledException::class);
});
