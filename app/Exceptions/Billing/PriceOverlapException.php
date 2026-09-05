<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Models\AiModel;
use App\Models\ModelPrice;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A new price period would overlap an existing one. Price history is never
 * rewritten or split silently (backdated or not); historical corrections are
 * Phase E adjustments, never edits.
 */
final class PriceOverlapException extends RuntimeException
{
    public static function for(AiModel $model, ModelPrice $existing, CarbonImmutable $from, ?CarbonImmutable $until): self
    {
        $newPeriod = $from->toIso8601String().' → '.($until?->toIso8601String() ?? 'open');
        $oldPeriod = $existing->effective_from?->toIso8601String().' → '.($existing->effective_until?->toIso8601String() ?? 'open');

        return new self(
            "Price period [{$newPeriod}] for model [{$model->external_id}] overlaps existing price #{$existing->id} [{$oldPeriod}]. "
            .'Existing price history is never rewritten or split; choose a start at or after the end of that period, '
            .'or close the current open period by publishing a price that starts after it began.'
        );
    }
}
