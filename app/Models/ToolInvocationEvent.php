<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ToolInvocationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One PERSISTED status transition of an invocation (Phase F2) — append-only.
 *
 * There is no `updated_at` and no code path that updates or deletes a row: a
 * transition is written once, in the same transaction as the projection it
 * describes, and `(tool_invocation_id, seq)` is unique so two writers can never
 * record the same step twice.
 *
 * Claim outcomes that changed nothing — replay, in-flight, conflict — write NO
 * event, because no state moved.
 */
class ToolInvocationEvent extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['tool_invocation_id', 'seq', 'from_status', 'to_status', 'reason_code', 'actor_ref', 'detail', 'occurred_at', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ToolInvocationStatus::class,
            'to_status' => ToolInvocationStatus::class,
            'detail' => 'array',
            'seq' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ToolInvocation, $this> */
    public function invocation(): BelongsTo
    {
        return $this->belongsTo(ToolInvocation::class, 'tool_invocation_id');
    }
}
