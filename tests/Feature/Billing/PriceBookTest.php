<?php

declare(strict_types=1);

use App\Data\Billing\PricePublication;
use App\Exceptions\Billing\PriceOverlapException;
use App\Models\AiModel;
use App\Models\ModelPrice;
use App\Services\Billing\Pricing\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function publication(string $from, ?string $until = null, string $input = '1', string $output = '2'): PricePublication
{
    return new PricePublication(
        currency: 'USD',
        inputPerMillion: $input,
        outputPerMillion: $output,
        cachedInputPerMillion: null,
        perRequest: '0',
        effectiveFrom: CarbonImmutable::parse($from),
        effectiveUntil: $until === null ? null : CarbonImmutable::parse($until),
    );
}

it('selects the period in force at occurred_at: start inclusive, end exclusive, open-ended last', function () {
    $model = AiModel::factory()->create();
    $book = app(PriceBook::class);

    $first = $book->publish($model, publication('2026-01-01 00:00:00'));
    $second = $book->publish($model, publication('2026-02-01 00:00:00', input: '5'));

    expect($book->priceFor($model->id, CarbonImmutable::parse('2025-12-31 23:59:59')))->toBeNull() // before any price
        ->and($book->priceFor($model->id, CarbonImmutable::parse('2026-01-01 00:00:00'))?->id)->toBe($first->id) // start inclusive
        ->and($book->priceFor($model->id, CarbonImmutable::parse('2026-01-31 23:59:59'))?->id)->toBe($first->id)
        ->and($book->priceFor($model->id, CarbonImmutable::parse('2026-02-01 00:00:00'))?->id)->toBe($second->id) // end exclusive
        ->and($book->priceFor($model->id, CarbonImmutable::parse('2030-01-01 00:00:00'))?->id)->toBe($second->id) // open-ended
        ->and($first->fresh()->effective_until?->toDateTimeString())->toBe('2026-02-01 00:00:00') // closed, not edited otherwise
        ->and((string) $first->fresh()->input_per_million)->toBe('1.00000000');
});

it('keeps exactly one open period per model and never edits rates of an existing row', function () {
    $model = AiModel::factory()->create();
    $book = app(PriceBook::class);

    $book->publish($model, publication('2026-01-01 00:00:00'));
    $book->publish($model, publication('2026-03-01 00:00:00', input: '9'));
    $book->publish($model, publication('2026-05-01 00:00:00', input: '3'));

    $rows = ModelPrice::query()->where('model_id', $model->id)->orderBy('effective_from')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->whereNull('effective_until'))->toHaveCount(1)
        ->and((string) $rows[0]->input_per_million)->toBe('1.00000000')
        ->and((string) $rows[1]->input_per_million)->toBe('9.00000000')
        ->and($rows[0]->effective_until?->toDateTimeString())->toBe('2026-03-01 00:00:00')
        ->and($rows[1]->effective_until?->toDateTimeString())->toBe('2026-05-01 00:00:00')
        ->and($rows[2]->effective_until)->toBeNull();
});

it('rejects a period that overlaps an existing one instead of rewriting or splitting history', function () {
    $model = AiModel::factory()->create();
    $book = app(PriceBook::class);

    $book->publish($model, publication('2026-01-01 00:00:00', '2026-02-01 00:00:00'));
    $book->publish($model, publication('2026-03-01 00:00:00'));

    // Inside a closed period (would split it).
    expect(fn () => $book->publish($model, publication('2026-01-15 00:00:00')))->toThrow(PriceOverlapException::class)
        // Starting before the open period began (would rewrite it).
        ->and(fn () => $book->publish($model, publication('2026-02-15 00:00:00')))->toThrow(PriceOverlapException::class)
        // Same start as the open period.
        ->and(fn () => $book->publish($model, publication('2026-03-01 00:00:00')))->toThrow(PriceOverlapException::class)
        // A closed period straddling an existing closed one.
        ->and(fn () => $book->publish($model, publication('2025-12-01 00:00:00', '2026-01-10 00:00:00')))->toThrow(PriceOverlapException::class);

    // Nothing was written or changed by the rejected attempts.
    $rows = ModelPrice::query()->where('model_id', $model->id)->orderBy('effective_from')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->effective_until?->toDateTimeString())->toBe('2026-02-01 00:00:00')
        ->and($rows[1]->effective_until)->toBeNull();
});

it('allows a period in a gap between existing periods (no overlap)', function () {
    $model = AiModel::factory()->create();
    $book = app(PriceBook::class);

    $book->publish($model, publication('2026-01-01 00:00:00', '2026-02-01 00:00:00'));
    $book->publish($model, publication('2026-04-01 00:00:00'));

    $gap = $book->publish($model, publication('2026-02-01 00:00:00', '2026-03-01 00:00:00', input: '7'));

    expect($book->priceFor($model->id, CarbonImmutable::parse('2026-02-15 00:00:00'))?->id)->toBe($gap->id)
        ->and($book->priceFor($model->id, CarbonImmutable::parse('2026-03-15 00:00:00')))->toBeNull()
        ->and(ModelPrice::query()->where('model_id', $model->id)->whereNull('effective_until')->count())->toBe(1);
});

it('validates publications: currency, precision, negative amounts, period order', function () {
    expect(fn () => new PricePublication('US', '1', '1', null, '0', CarbonImmutable::now()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PricePublication('USD', '1.123456789', '1', null, '0', CarbonImmutable::now()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PricePublication('USD', '-1', '1', null, '0', CarbonImmutable::now()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PricePublication('USD', '1', '1', null, '0', CarbonImmutable::now(), CarbonImmutable::now()->subDay()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PricePublication('USD', '1', '1', null, '0', CarbonImmutable::now(), unit: 'gallon'))->toThrow(InvalidArgumentException::class);
});
