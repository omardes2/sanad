<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The CURRENT state of one tool invocation (Phase F2) — the projection.
 *
 * Its identity is the server-owned CALL SLOT (`msg:<id>:call:<n>`), which the
 * unique index arbitrates; which tool and version were claimed for that slot,
 * and the hash of their canonical input, are FACTS on the row, so a different
 * tool, version or input at the same slot conflicts instead of creating a second
 * invocation. How it reached its status is the append-only
 * `tool_invocation_events` history, and `version` is both the concurrency
 * contract and the number of transitions that have happened.
 *
 * `input` holds only what `ToolInputPersistence` explicitly allows (nothing, for
 * every tool shipped so far) and `input_fields` only the names of the declared
 * fields the call carried — raw arguments are never stored.
 *
 * Only `ToolInvocationStore` writes this row.
 */
class ToolInvocation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscriber_id', 'tool_key', 'tool_version', 'capability', 'side_effect',
        'idempotency_key', 'input_hash', 'input', 'input_fields', 'status', 'message_id',
        'conversation_id', 'call_index', 'output', 'failure_kind',
        'refusal_reason', 'duration_ms', 'started_at', 'finished_at', 'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ToolInvocationStatus::class,
            'side_effect' => ToolSideEffect::class,
            'failure_kind' => ToolInvocationFailureKind::class,
            'refusal_reason' => ToolInvocationRefusalReason::class,
            'input' => 'array',
            'input_fields' => 'array',
            'output' => 'array',
            'tool_version' => 'integer',
            'call_index' => 'integer',
            'duration_ms' => 'integer',
            'version' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subscriber_id');
    }

    /** @return HasMany<ToolInvocationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ToolInvocationEvent::class)->orderBy('seq');
    }

    /** `memory.read@1` — the exact contract this invocation was claimed against. */
    public function toolKeyValue(): string
    {
        return $this->tool_key.'@'.$this->tool_version;
    }

    /**
     * What the ledger links to: the invocation's own persisted identity, NOT
     * its idempotency key. The key is request de-duplication; this is the
     * domain identity, and it leaks no message, tool or call structure into
     * the finance tables.
     */
    public function ledgerRef(): string
    {
        return (string) $this->getKey();
    }
}
