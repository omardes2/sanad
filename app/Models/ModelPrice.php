<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModelPriceSource;
use App\Services\Ai\Catalog\CatalogCache;
use Carbon\CarbonImmutable;
use Database\Factories\ModelPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One HISTORICAL price period of a model: [effective_from, effective_until).
 * Append-only by design — PriceBook is the only writer; it never edits the
 * rates of an existing row, it closes a period and opens a new one. Usage
 * events reference a row by id and also carry a snapshot of its rates, so a
 * price row can never change the cost of a past event.
 */
class ModelPrice extends Model
{
    /** @use HasFactory<ModelPriceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'model_id',
        'currency',
        'unit',
        'input_per_million',
        'output_per_million',
        'cached_input_per_million',
        'per_request',
        'effective_from',
        'effective_until',
        'source',
        'note',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_per_million' => 'decimal:8',
            'output_per_million' => 'decimal:8',
            'cached_input_per_million' => 'decimal:8',
            'per_request' => 'decimal:8',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'source' => ModelPriceSource::class,
        ];
    }

    protected static function booted(): void
    {
        static::saved(static fn () => CatalogCache::flushAfterCommit());
        static::deleted(static fn () => CatalogCache::flushAfterCommit());
    }

    /** @return BelongsTo<AiModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function isOpen(): bool
    {
        return $this->effective_until === null;
    }

    public function coversAt(CarbonImmutable $at): bool
    {
        return $this->effective_from <= $at
            && ($this->effective_until === null || $this->effective_until > $at);
    }

    /**
     * The exact rates used to cost an event, stored on the event itself so the
     * cost stays explainable even if this row is later deleted.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'price_id' => $this->id,
            'currency' => $this->currency,
            'unit' => $this->unit,
            'input_per_million' => (string) $this->input_per_million,
            'output_per_million' => (string) $this->output_per_million,
            'cached_input_per_million' => $this->cached_input_per_million === null ? null : (string) $this->cached_input_per_million,
            'per_request' => (string) $this->per_request,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_until' => $this->effective_until?->toIso8601String(),
        ];
    }
}
