<?php

declare(strict_types=1);

namespace App\Data\Ai\Health;

/**
 * What an adapter DECLARES about health probes (Phase C3, decision C). A
 * non-billable authenticated probe is never assumed: an adapter that does
 * not declare one gets `auth` recorded as skipped/unsupported.
 */
final readonly class HealthCapabilities
{
    public function __construct(
        public bool $nonBillableAuthProbe = false,
        public ?string $authProbePath = null,
    ) {}
}
