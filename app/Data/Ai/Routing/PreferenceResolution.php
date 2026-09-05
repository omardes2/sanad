<?php

declare(strict_types=1);

namespace App\Data\Ai\Routing;

/**
 * Which provider the router prefers and WHY (Phase C4):
 *  - source `env`          : mode env — AI_PROVIDER, today's behaviour;
 *  - source `db`           : mode db  — the enabled is_primary provider;
 *  - source `env_fallback` : mode db but no usable primary — AI_PROVIDER used
 *                            in a DEGRADED state (emergency only).
 */
final readonly class PreferenceResolution
{
    public function __construct(
        public string $mode,
        public string $provider,
        public string $source,
        public ?int $primaryProviderId = null,
        public ?string $degradedReason = null,
    ) {}

    public function degraded(): bool
    {
        return $this->source === 'env_fallback';
    }
}
