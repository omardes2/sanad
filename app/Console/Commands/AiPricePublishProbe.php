<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Billing\PricePublication;
use App\Enums\ModelPriceSource;
use App\Exceptions\Billing\PriceOverlapException;
use App\Models\AiModel;
use App\Services\Billing\Pricing\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Testing-only probe: publishes exactly ONE price period for a model and
 * prints "published" or "rejected". Launched as many concurrent OS processes
 * by the real PostgreSQL concurrency test to prove PriceBook's parent-row lock
 * serialises publications even for a model that has no price yet.
 * Not intended for production use.
 */
class AiPricePublishProbe extends Command
{
    protected $signature = 'sanad:ai-price-probe {model} {from} {input} {output}';

    protected $description = 'Testing only: publish one price period and print the outcome';

    protected $hidden = true;

    public function handle(PriceBook $book): int
    {
        $model = AiModel::query()->find((int) $this->argument('model'));

        if ($model === null) {
            $this->line('missing');

            return self::FAILURE;
        }

        try {
            $book->publish($model, new PricePublication(
                currency: (string) config('billing.cost_currency', 'USD'),
                inputPerMillion: (string) $this->argument('input'),
                outputPerMillion: (string) $this->argument('output'),
                cachedInputPerMillion: null,
                perRequest: '0',
                effectiveFrom: CarbonImmutable::parse((string) $this->argument('from')),
                source: ModelPriceSource::Seed,
            ));
            $this->line('published');
        } catch (PriceOverlapException) {
            $this->line('rejected');
        }

        return self::SUCCESS;
    }
}
