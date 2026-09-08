<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ToolInvocationStatus;
use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One PERSISTED status transition of an invocation (Phase F2) — append-only.
 *
 * Append-only, enforced the way this repository already enforces it:
 * `ImmutableFinancialRecord` makes any update or delete through the model throw,
 * there is no `updated_at` to move, `ToolInvocationStore` is the only writer,
 * and `(tool_invocation_id, seq)` is unique so two writers can never record the
 * same step twice. At the database layer the invocation itself is
 * `restrictOnDelete`, so history is never removed just because the projection
 * would be.
 *
 * Claim outcomes that changed nothing — replay, in-flight, conflict — write NO
 * event, because no state moved.
 */
class ToolInvocationEvent extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

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
