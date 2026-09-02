<?php

declare(strict_types=1);

use App\Data\AgentResponseData;
use App\Data\InboundMessageData;
use App\Data\OutboundMessageData;
use App\Enums\ChannelType;
use App\Enums\MessageType;
use Carbon\CarbonImmutable;

it('builds an InboundMessageData with typed properties', function () {
    $at = CarbonImmutable::parse('2026-01-01T10:00:00Z');

    $dto = new InboundMessageData(
        channel: ChannelType::Web,
        externalMessageId: 'ext-1',
        externalUserId: 'web-user-1',
        type: MessageType::Text,
        text: 'مرحبا',
        metadata: ['source' => 'test'],
        receivedAt: $at,
    );

    expect($dto->channel)->toBe(ChannelType::Web)
        ->and($dto->externalMessageId)->toBe('ext-1')
        ->and($dto->type)->toBe(MessageType::Text)
        ->and($dto->text)->toBe('مرحبا')
        ->and($dto->media)->toBeNull()
        ->and($dto->metadata)->toBe(['source' => 'test'])
        ->and($dto->receivedAt())->toBe($at);
});

it('defaults InboundMessageData.receivedAt() to now when not given', function () {
    $dto = new InboundMessageData(
        channel: ChannelType::Web,
        externalMessageId: 'ext-2',
        externalUserId: 'web-user-1',
        type: MessageType::Text,
    );

    expect($dto->receivedAt)->toBeNull()
        ->and($dto->receivedAt())->toBeInstanceOf(CarbonImmutable::class);
});

it('builds Outbound and AgentResponse DTOs', function () {
    $out = new OutboundMessageData(ChannelType::Web, 'web-user-1', MessageType::Text, 'hi');
    expect($out->channel)->toBe(ChannelType::Web)
        ->and($out->text)->toBe('hi');

    $resp = new AgentResponseData('reply');
    expect($resp->text)->toBe('reply')
        ->and($resp->type)->toBe(MessageType::Text)
        ->and($resp->metadata)->toBe([]);
});
