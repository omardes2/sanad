<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Billing\PricePublication;
use App\Enums\ModelPriceSource;
use App\Exceptions\Billing\PriceOverlapException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ModelPrice;
use App\Services\Billing\Pricing\CostCalculator;
use App\Services\Billing\Pricing\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use InvalidArgumentException;
use Throwable;

/**
 * Publishes a model price period explicitly (Phase B2). This is the ONLY
 * supported way to enter prices: every value is typed by a human, previewed
 * (including a worked example so a per-1K vs per-1M mistake is visible), and
 * confirmed. Rates are per MILLION tokens in the cost currency.
 *
 * A new period closes the current open one at its start. It NEVER rewrites or
 * splits existing history: any overlap with an existing period is rejected,
 * backdated or not (--allow-backdate only permits a start in the past). Stored
 * usage events are never touched — historical corrections are Phase E
 * adjustments.
 */
class AiPriceCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'sanad:ai:price
        {--model= : Model handle as provider:external_id}
        {--input= : Input price per million tokens}
        {--output= : Output price per million tokens}
        {--cached= : Cached-input price per million tokens (defaults to the input price)}
        {--per-request=0 : Flat amount added per request}
        {--currency= : ISO 4217 currency (defaults to billing.cost_currency)}
        {--effective-from= : Period start (ISO 8601); defaults to now}
        {--effective-until= : Optional period end (exclusive)}
        {--note= : Free-text note stored with the price}
        {--allow-backdate : Permit a start in the past (still rejected if it overlaps any existing period)}
        {--yes : Skip the interactive confirmation}
        {--force : Skip the production confirmation prompt}';

    protected $description = 'Publish a historical model price period (explicit values only; never rewrites existing history)';

    public function handle(PriceBook $book, CostCalculator $calculator): int
    {
        $handle = trim((string) $this->option('model'));
        [$providerKey, $externalId] = array_pad(explode(':', $handle, 2), 2, '');

        if ($providerKey === '' || $externalId === '') {
            $this->error('--model is required as provider:external_id (e.g. openai:gpt-4.1-mini).');

            return self::FAILURE;
        }

        $model = AiModel::query()
            ->whereIn('provider_id', AiProvider::query()->where('key', $providerKey)->select('id'))
            ->where('external_id', $externalId)
            ->first();

        if ($model === null) {
            $this->error("Unknown model [{$handle}]. Register it first with sanad:ai:bootstrap.");

            return self::FAILURE;
        }

        try {
            $from = $this->option('effective-from') !== null
                ? CarbonImmutable::parse((string) $this->option('effective-from'))
                : CarbonImmutable::now();
            $until = $this->option('effective-until') !== null
                ? CarbonImmutable::parse((string) $this->option('effective-until'))
                : null;

            $publication = new PricePublication(
                currency: (string) ($this->option('currency') ?? config('billing.cost_currency', 'USD')),
                inputPerMillion: $this->requiredDecimal('input'),
                outputPerMillion: $this->requiredDecimal('output'),
                cachedInputPerMillion: $this->option('cached') !== null ? (string) $this->option('cached') : null,
                perRequest: (string) $this->option('per-request'),
                effectiveFrom: $from,
                effectiveUntil: $until,
                source: ModelPriceSource::Manual,
                note: $this->option('note') !== null ? (string) $this->option('note') : null,
            );
        } catch (InvalidArgumentException|Throwable $e) {
            $this->error('Invalid input: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($publication->effectiveFrom < CarbonImmutable::now()->subMinute() && ! $this->option('allow-backdate')) {
            $this->error('effective-from is in the past. Re-run with --allow-backdate if that is intended (existing periods are still never rewritten).');

            return self::FAILURE;
        }

        $preview = new ModelPrice([
            'currency' => $publication->currency,
            'unit' => $publication->unit,
            'input_per_million' => $publication->inputPerMillion,
            'output_per_million' => $publication->outputPerMillion,
            'cached_input_per_million' => $publication->cachedInputPerMillion,
            'per_request' => $publication->perRequest,
        ]);
        $example = $calculator->estimateTokens($preview, 1000, 300, 0);

        $this->table(['Field', 'Value'], [
            ['Model', $handle],
            ['Currency', strtoupper($publication->currency)],
            ['Input / 1M tokens', $publication->inputPerMillion],
            ['Output / 1M tokens', $publication->outputPerMillion],
            ['Cached input / 1M tokens', $publication->cachedInputPerMillion ?? '(= input)'],
            ['Per request', $publication->perRequest],
            ['Effective from (inclusive)', $publication->effectiveFrom->toIso8601String()],
            ['Effective until (exclusive)', $publication->effectiveUntil?->toIso8601String() ?? 'open'],
            ['Example: 1000 in + 300 out', $example.' '.strtoupper($publication->currency)],
        ]);

        $open = $book->openPriceFor($model->id);

        if ($open !== null) {
            $this->line("Current open price #{$open->id} (from {$open->effective_from?->toIso8601String()}) will be CLOSED at the new start.");
        }

        if (! $this->option('yes') && ! $this->confirm('Publish this price period?')) {
            $this->line('Aborted; nothing written.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed('Application is in production — publish this price?')) {
            return self::FAILURE;
        }

        try {
            $price = $book->publish($model, $publication);
        } catch (PriceOverlapException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Published price #{$price->id} for {$handle}.");

        return self::SUCCESS;
    }

    private function requiredDecimal(string $option): string
    {
        $value = $this->option($option);

        if ($value === null || trim((string) $value) === '') {
            throw new InvalidArgumentException("--{$option} is required.");
        }

        return trim((string) $value);
    }
}
