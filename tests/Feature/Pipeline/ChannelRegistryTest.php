<?php

declare(strict_types=1);

use App\Channels\ChannelRegistry;
use App\Channels\WebSimulatorChannelAdapter;
use App\Channels\WhatsAppChannelAdapter;
use App\Enums\ChannelType;

it('resolves the web simulator adapter for the web channel', function () {
    $adapter = app(ChannelRegistry::class)->for(ChannelType::Web);

    expect($adapter)->toBeInstanceOf(WebSimulatorChannelAdapter::class)
        ->and($adapter->channel())->toBe(ChannelType::Web);
});

it('resolves the whatsapp adapter for the whatsapp channel', function () {
    $adapter = app(ChannelRegistry::class)->for(ChannelType::WhatsApp);

    expect($adapter)->toBeInstanceOf(WhatsAppChannelAdapter::class)
        ->and($adapter->channel())->toBe(ChannelType::WhatsApp);
});

it('lists supported channels', function () {
    expect(app(ChannelRegistry::class)->supported())
        ->toBe([ChannelType::Web, ChannelType::WhatsApp]);
});
