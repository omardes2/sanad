<?php

declare(strict_types=1);

namespace App\Services\Billing\Pricing;

use App\Data\Billing\PricePublication;
use App\Exceptions\Billing\PriceOverlapException;
use App\Models\AiModel;
use App\Models\ModelPrice;
use App\Services\Ai\Catalog\CatalogCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The historical price book: the ONLY writer of model_prices.
 *
 * Lookup — priceFor(model, at): the period with effective_from <= at and
 * (effective_until IS NULL or effective_until > at), latest start first. The
 * start is inclusive and the end exclusive, so no instant is covered by two
 * periods. `at` is the event's occurred_at, never "now".
 *
 * Publication — publish(model, data): inside one transaction the PARENT
 * ai_models row is locked (SELECT ... FOR UPDATE) before anything is examined,
 * so two concurrent publications for a model — even one that has no price yet,
 * where no price row exists to lock — are serialised. Then:
 *  - any existing period that overlaps the new one is a hard rejection
 *    (history is never rewritten or split silently, backdated or not);
 *  - the currently open period that STARTED before the new start is closed at
 *    the new start (the normal "price changed" case);
 *  - the new period is inserted.
 * Existing rows' rates are never updated. Costs already recorded on
 * usage_events are never touched.
 */
class PriceBook
{
    public function priceFor(int $modelId, CarbonImmutable $at): ?ModelPrice
    {
        return ModelPrice::query()
            ->where('model_id', $modelId)
            ->where('effective_from', '<=', $at)
            ->where(static function ($query) use ($at): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $at);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function openPriceFor(int $modelId): ?ModelPrice
    {
        return ModelPrice::query()
            ->where('model_id', $modelId)
            ->whereNull('effective_until')
            ->first();
    }

    /**
     * @throws PriceOverlapException when the new period overlaps an existing one
     */
    public function publish(AiModel $model, PricePublication $data): ModelPrice
    {
        $price = DB::transaction(function () use ($model, $data): ModelPrice {
            // Serialise all publications for this model on the parent row —
            // the model always exists, even when it has no price yet.
            AiModel::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();

            $from = $data->effectiveFrom;
            $until = $data->effectiveUntil;

            $overlap = ModelPrice::query()
                ->where('model_id', $model->id)
                ->where(static function ($query) use ($from, $until): void {
                    // [f, u) overlaps [from, until) unless it ends at or before
                    // `from` or starts at or after `until`. The single exception —
                    // an OPEN period that started before `from` — is not an
                    // overlap: it is the period we close at `from`.
                    $query->where(static function ($q) use ($from, $until): void {
                        $q->whereNull('effective_until')
                            ->where('effective_from', '>=', $from)
                            ->when($until !== null, static fn ($qq) => $qq->where('effective_from', '<', $until));
                    })->orWhere(static function ($q) use ($from, $until): void {
                        $q->whereNotNull('effective_until')
                            ->where('effective_until', '>', $from)
                            ->when($until !== null, static fn ($qq) => $qq->where('effective_from', '<', $until));
                    });
                })
                ->orderBy('effective_from')
                ->first();

            if ($overlap !== null) {
                throw PriceOverlapException::for($model, $overlap, $from, $until);
            }

            // Close the current open period at the new start (if any).
            ModelPrice::query()
                ->where('model_id', $model->id)
                ->whereNull('effective_until')
                ->where('effective_from', '<', $from)
                ->update(['effective_until' => $from, 'updated_at' => CarbonImmutable::now()]);

            return ModelPrice::query()->create([
                'model_id' => $model->id,
                'currency' => strtoupper($data->currency),
                'unit' => $data->unit,
                'input_per_million' => $data->inputPerMillion,
                'output_per_million' => $data->outputPerMillion,
                'cached_input_per_million' => $data->cachedInputPerMillion,
                'per_request' => $data->perRequest,
                'effective_from' => $from,
                'effective_until' => $until,
                'source' => $data->source,
                'note' => $data->note,
                'created_by' => $data->createdBy,
            ]);
        });

        CatalogCache::flush();

        return $price;
    }
}
