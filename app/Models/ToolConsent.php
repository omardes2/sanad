<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolConsentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current consent of one subscriber for one capability (Phase F1).
 *
 * Only `ToolConsentService` writes this row: it locks it, checks the version
 * the caller saw, moves the status and audits — all in one transaction. The
 * identity (subscriber + capability) never changes; the history lives in the
 * audit log.
 */
class ToolConsent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['subscriber_id', 'capability', 'status', 'granted_at', 'revoked_at', 'reason_code', 'evidence_ref', 'version', 'updated_by_ref'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capability' => ToolCapability::class,
            'status' => ToolConsentStatus::class,
            'reason_code' => ToolConsentReason::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subscriber_id');
    }

    public function isGranted(): bool
    {
        return $this->status === ToolConsentStatus::Granted;
    }

    /** The concurrency contract a caller states back: `c:<version>`. */
    public function stateToken(): string
    {
        return 'c:'.$this->version;
    }
}
