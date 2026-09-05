<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CredentialSource;
use App\Enums\HealthCheckKind;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider health probe result (Phase C3). Append-only history written
 * by ProviderHealthService; pruned by sanad:ai:health:prune.
 */
class ProviderHealthCheck extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id', 'kind', 'trigger', 'status', 'credential_id', 'credential_source', 'candidate_base_url',
        'latency_ms', 'http_status', 'error_class', 'error_code', 'cost_incurred', 'usage_event_id',
        'checked_by_ref', 'details', 'checked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => HealthCheckKind::class,
            'trigger' => HealthCheckTrigger::class,
            'status' => HealthCheckStatus::class,
            'credential_source' => CredentialSource::class,
            'candidate_base_url' => 'boolean',
            'latency_ms' => 'integer',
            'http_status' => 'integer',
            'cost_incurred' => 'boolean',
            'details' => 'array',
            'checked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class, 'credential_id');
    }
}
