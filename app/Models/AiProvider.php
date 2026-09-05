<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiOperation;
use App\Services\Ai\Catalog\CatalogCache;
use Database\Factories\AiProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An AI provider as operational DATA (Phase B2). `key` matches the key the
 * AiManager resolves (openai, groq, ...). The row never holds a credential:
 * keys stay in the environment until Phase C; `credentials_ref` only names the
 * env variable. `is_primary` is stored for the Phase C cutover and is NOT read
 * by the router in B2 (AI_PROVIDER remains the operational preference).
 */
class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'driver',
        'base_url',
        'credentials_ref',
        'capabilities',
        'is_enabled',
        'is_primary',
        'priority',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_enabled' => 'boolean',
            'is_primary' => 'boolean',
            'priority' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(static fn () => CatalogCache::flushAfterCommit());
        static::deleted(static fn () => CatalogCache::flushAfterCommit());
    }

    /** @return HasMany<AiModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id');
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

        return $operations;
    }
}
