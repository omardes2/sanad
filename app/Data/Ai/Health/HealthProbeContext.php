<?php

declare(strict_types=1);

namespace App\Data\Ai\Health;

/**
 * Inputs of one probe: timeouts, the model to name in an inference probe, and
 * the catalog model ids to look for in an auth probe's model list.
 *
 * @param  list<string>  $expectedModels
 */
final readonly class HealthProbeContext
{
    /**
     * @param  list<string>  $expectedModels
     */
    public function __construct(
        public int $connectTimeout = 5,
        public int $timeout = 10,
        public ?string $model = null,
        public array $expectedModels = [],
    ) {}
}
