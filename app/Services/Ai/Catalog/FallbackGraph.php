<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Exceptions\Ai\FallbackCycleException;
use App\Models\AiModel;

/**
 * Keeps the model fallback graph acyclic (Phase C2). A fallback chain is
 * followed at most MAX_DEPTH hops; reaching the model itself, or exceeding
 * the depth, is rejected before anything is written.
 */
final class FallbackGraph
{
    public const MAX_DEPTH = 5;

    /**
     * @throws FallbackCycleException
     */
    public static function assertAcyclic(AiModel $model, ?int $fallbackId): void
    {
        if ($fallbackId === null) {
            return;
        }

        if ($fallbackId === $model->id) {
            throw FallbackCycleException::selfReference($model);
        }

        $visited = [$model->id];
        $current = $fallbackId;

        for ($depth = 1; $depth <= self::MAX_DEPTH; $depth++) {
            if (in_array($current, $visited, true)) {
                throw FallbackCycleException::cycle($model, $visited);
            }

            $visited[] = $current;

            /** @var int|null $next */
            $next = AiModel::query()->whereKey($current)->value('fallback_model_id');

            if ($next === null) {
                return;
            }

            $current = (int) $next;
        }

        throw FallbackCycleException::tooDeep($model, self::MAX_DEPTH);
    }
}
