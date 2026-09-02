<?php

declare(strict_types=1);

namespace App\Channels;

use App\Contracts\ChannelAdapter;
use App\Enums\ChannelType;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the right ChannelAdapter for a given ChannelType via the Laravel
 * service container. This keeps channel selection in one place so callers
 * (e.g. MessageProcessor, the queue job) never branch on whatsapp/web.
 */
class ChannelRegistry
{
    /**
     * @var array<string, class-string<ChannelAdapter>>
     */
    private array $adapters = [
        ChannelType::Web->value => WebSimulatorChannelAdapter::class,
        ChannelType::WhatsApp->value => WhatsAppChannelAdapter::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function for(ChannelType $channel): ChannelAdapter
    {
        $adapter = $this->adapters[$channel->value]
            ?? throw new InvalidArgumentException("No channel adapter registered for [{$channel->value}].");

        return $this->container->make($adapter);
    }

    /**
     * @return list<ChannelType>
     */
    public function supported(): array
    {
        return array_map(
            static fn (string $value): ChannelType => ChannelType::from($value),
            array_keys($this->adapters),
        );
    }
}
