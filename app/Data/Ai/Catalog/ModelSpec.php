<?php

declare(strict_types=1);

namespace App\Data\Ai\Catalog;

use App\Enums\AiOperation;

/**
 * One routable model in the catalog: which provider serves it, its external id,
 * what it can do, and its ordering. Config-backed in Phase A; the same shape is
 * what a database-backed catalog (managed from Sanad Admin) will produce later.
 *
 * @param  list<AiOperation>  $capabilities
 */
final readonly class ModelSpec
{
    /**
     * @param  list<AiOperation>  $capabilities
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $capabilities = [AiOperation::Chat],
        public bool $enabled = true,
        public int $priority = 0,
        public ?string $fallbackModel = null,
    ) {}

    public function supports(AiOperation $operation): bool
    {
        return in_array($operation, $this->capabilities, true);
    }
}
