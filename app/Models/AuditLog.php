<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit trail, written only by App\Services\Audit\AuditLogger.
 * `metadata` = { changes: { field: { from, to } }, context: {...} }, already
 * redacted at write time. Rows are never updated or deleted by the app.
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * Audit logs are append-only: they have a created_at but no updated_at.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'actor',
        'actor_ref',
        'action',
        'subject_type',
        'subject_id',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function changes(): array
    {
        return (array) ($this->metadata['changes'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return (array) ($this->metadata['context'] ?? []);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
