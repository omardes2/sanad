<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiOperation;
use App\Services\Ai\Catalog\CatalogCache;
use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A routable model of a provider (Phase B2): the id we SEND (`external_id`),
 * the ids the provider may REPORT for it (`aliases`), what it can do, its
 * ordering, and an optional fallback (which may belong to another provider).
 */
class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id',
        'external_id',
        'name',
        'aliases',
        'capabilities',
        'supports_tools',
        'context_window',
        'max_output_tokens',
        'is_enabled',
        'priority',
        'fallback_model_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'capabilities' => 'array',
            'supports_tools' => 'boolean',
            'context_window' => 'integer',
            'max_output_tokens' => 'integer',
            'is_enabled' => 'boolean',
            'priority' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(static fn () => CatalogCache::flushAfterCommit());
        static::deleted(static fn () => CatalogCache::flushAfterCommit());
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /** @return BelongsTo<AiModel, $this> */
    public function fallback(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'fallback_model_id');
    }

    /** @return HasMany<ModelPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(ModelPrice::class, 'model_id');
    }

    /**
     * @return list<AiOperation>
     */
    public function operations(): array
    {
        $operations = [];

        foreach ((array) ($this->capabilities ?? []) as $capability) {
            $operation = AiOperation::tryFrom((string) $capability);

            if ($operation !== null) {
                $operations[] = $operation;
            }
        }

        return $operations !== [] ? $operations : [AiOperation::Chat];
    }

    public function supports(AiOperation $operation): bool
    {
        return in_array($operation, $this->operations(), true);
    }

    /**
     * Whether an id reported by the provider designates this model.
     */
    public function matches(string $reportedId): bool
    {
        if ($reportedId === $this->external_id) {
            return true;
        }

        foreach ((array) ($this->aliases ?? []) as $alias) {
            if (is_string($alias) && $alias === $reportedId) {
                return true;
            }
        }

        return false;
    }

    /**
     * "provider:external_id" — the human handle used by the artisan commands.
     */
    public function handle(): string
    {
        return $this->provider->key.':'.$this->external_id;
    }
}
