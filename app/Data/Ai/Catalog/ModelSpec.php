<?php

declare(strict_types=1);

namespace App\Data\Ai\Catalog;

use App\Enums\AiOperation;

/**
 * One routable model in the catalog: which provider serves it, its external id,
 * what it can do, and its ordering. Config-backed in Phase A; the database-
 * backed catalog (Phase B2) produces the same shape and additionally fills the
 * optional ids (modelId / providerId) so pricing can find the row, and the
 * fallback's provider when the fallback belongs to another provider.
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
        public ?string $fallbackProvider = null,
        public ?int $modelId = null,
        public ?int $providerId = null,
    ) {}

    public function supports(AiOperation $operation): bool
    {
        return in_array($operation, $this->capabilities, true);
    }

    /**
     * Whether $candidate is the declared fallback of this spec.
     */
    public function fallsBackTo(ModelSpec $candidate): bool
    {
        if ($this->fallbackModel === null || $candidate->model !== $this->fallbackModel) {
            return false;
        }

        return $this->fallbackProvider === null || $candidate->provider === $this->fallbackProvider;
    }
}
