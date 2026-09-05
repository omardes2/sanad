<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

use App\Data\Ai\Health\HealthCapabilities;
use App\Data\Ai\Health\HealthProbeContext;
use App\Data\Ai\Health\HealthProbeResult;
use App\Enums\HealthCheckKind;

/**
 * Provider-specific health probes (Phase C3). The adapter knows its own wire
 * protocol, so it decides HOW to probe; ProviderHealthService decides WHEN,
 * with which credential, and records the outcome.
 */
interface SupportsHealthChecks
{
    public function healthCapabilities(): HealthCapabilities;

    public function healthCheck(HealthCheckKind $kind, HealthProbeContext $context): HealthProbeResult;
}
