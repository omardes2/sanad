<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\Security\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of audit_logs (Phase C0 foundation).
 *
 * Semantics of a row:
 *  - action   : dot-namespaced event name (see AuditActions)
 *  - user_id  : the authenticated actor (live FK, nulled if the user is deleted)
 *  - actor    : "user" | "console" | "system" (how the action was initiated)
 *  - actor_ref: immutable, non-personal snapshot of WHO acted — "user:{id}",
 *               "console" or "system" — so history keeps its author after the
 *               account is hard-deleted (no PII: the internal id only)
 *  - subject  : optional polymorphic target (role assignment → the User, a
 *               price publication → the ModelPrice, ...)
 *  - metadata : { "changes": { field: { "from": ?, "to": ? } }, "context": {...} }
 *  - ip_address / user_agent : request context when available
 *  - created_at only: append-only, never updated or deleted by the app.
 *
 * Everything in `changes` and `context` passes through SecretRedactor before
 * it is written: explicit registry first, defensive patterns second.
 *
 * Atomicity: an audit row must never describe a change that did not happen.
 * saveWithAudit() performs the model save AND the audit insert in one
 * transaction (a savepoint when one is already open), so a failed or rolled
 * back change rolls the audit row back too. Callers that write their own
 * changes must call record() INSIDE their transaction (RbacSynchronizer does).
 */
class AuditLogger
{
    public function __construct(private readonly SecretRedactor $redactor) {}

    /**
     * @param  array<string, array{from: mixed, to: mixed}|mixed>  $changes
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
    {
        $user = Auth::user();
        $request = $this->currentRequest();
        $actor = $user !== null ? 'user' : (app()->runningInConsole() ? 'console' : 'system');

        $metadata = [
            'changes' => $this->redactor->redact($changes, null, $subject),
            'context' => $this->redactor->redact($context),
        ];

        return AuditLog::query()->create([
            'user_id' => $user?->getAuthIdentifier(),
            'actor' => $actor,
            'actor_ref' => $user !== null ? 'user:'.$user->getAuthIdentifier() : $actor,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 512) : null,
        ]);
    }

    /**
     * Save a dirty model AND record its before/after diff in ONE transaction.
     * The diff is captured while the model is still dirty (Eloquent syncs the
     * originals on save); the audit row is written only after save() succeeded,
     * and both are rolled back together if either fails. Returns null when
     * nothing was dirty (nothing saved, nothing audited).
     *
     * @param  array<string, mixed>  $context
     *
     * @throws \Throwable when the save fails — nothing is audited in that case
     */
    public function saveWithAudit(string $action, Model $model, array $context = []): ?AuditLog
    {
        $changes = [];

        foreach ($model->getDirty() as $attribute => $to) {
            $changes[$attribute] = ['from' => $model->getOriginal($attribute), 'to' => $to];
        }

        if ($changes === []) {
            return null;
        }

        return DB::transaction(function () use ($action, $model, $changes, $context): ?AuditLog {
            if (! $model->save()) {
                // A `saving` listener vetoed the change: nothing happened, so
                // nothing is audited.
                return null;
            }

            return $this->record($action, $model, $changes, $context);
        });
    }

    private function currentRequest(): ?Request
    {
        // Artisan has a synthetic request with no client context.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        try {
            return app('request');
        } catch (\Throwable) {
            return null;
        }
    }
}
