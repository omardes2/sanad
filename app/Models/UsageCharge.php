<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One accepted quota charge (enforcement only). Written exclusively by
 * UsageEngine inside the same transaction as the counter increment; its unique
 * idempotency_key is the engine's replay guard. Not a cost record — see
 * UsageEvent for the ledger.
 */
class UsageCharge extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscriber_id',
        'dimension',
        'idempotency_key',
        'weight',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subscriber_id');
    }
}
