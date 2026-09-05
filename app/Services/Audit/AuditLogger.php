<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\Security\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The ONLY writer of audit_logs (Phase C0 foundation).
 *
 * Semantics of a row:
 *  - action   : dot-namespaced event name (see AuditActions)
 *  - user_id  : the authenticated actor (nullable — console/system actions)
 *  - actor    : "user" | "console" | "system" (how the action was initiated)
 *  - subject  : optional polymorphic target (role assignment → the User, a
 *               price publication → the ModelPrice, ...)
 *  - metadata : { "changes": { field: { "from": ?, "to": ? } }, "context": {...} }
 *  - ip_address / user_agent : request context when available
 *  - created_at only: append-only, never updated or deleted by the app.
 *
 * Everything in `changes` and `context` passes through SecretRedactor before
 * it is written: explicit registry first, defensive patterns second.
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

        $metadata = [
            'changes' => $this->redactor->redact($changes, null, $subject),
            'context' => $this->redactor->redact($context),
        ];

        return AuditLog::query()->create([
            'user_id' => $user?->getAuthIdentifier(),
            'actor' => $user !== null ? 'user' : (app()->runningInConsole() ? 'console' : 'system'),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 512) : null,
        ]);
    }

    /**
     * Convenience: audit the pending (dirty) changes of a model. Call it
     * BEFORE save() — Eloquent syncs the "original" values on save, so the
     * before/after pair is only available while the model is still dirty.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordModelChanges(string $action, Model $model, array $context = []): ?AuditLog
    {
        $changes = [];

        foreach ($model->getDirty() as $attribute => $to) {
            $changes[$attribute] = ['from' => $model->getOriginal($attribute), 'to' => $to];
        }

        if ($changes === []) {
            return null;
        }

        return $this->record($action, $model, $changes, $context);
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
