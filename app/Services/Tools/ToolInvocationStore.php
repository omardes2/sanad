<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\Billing\UsageRecord;
use App\Data\Tools\ToolCallRequest;
use App\Data\Tools\ToolClaim;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\UsageDimension;
use App\Enums\UsageEventOutcome;
use App\Exceptions\Tools\ToolRuleException;
use App\Models\ToolInvocation;
use App\Models\ToolInvocationEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\UsageRecorder;
use App\Support\Audit\AuditActions;
use App\Support\Tools\ToolAuthorization;
use App\Support\Tools\ToolInputPersistence;
use App\Support\Tools\ToolInvocationTransitions;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of `tool_invocations` and `tool_invocation_events` (Phase F2).
 *
 * CLAIM — the identity is the server-owned CALL SLOT (`msg:<id>:call:<n>`),
 * unique in the database, so the first writer creates the `planned` row inside a
 * savepoint and the index arbitrates the race. Every later writer at the same
 * slot OBSERVES the row and mutates nothing. What it is compared against is the
 * full set of claim facts — TOOL KEY, TOOL VERSION and INPUT HASH — not the hash
 * alone:
 *   same tool + same version + same input + terminal      ⇒ REPLAY   (the recorded result stands)
 *   same tool + same version + same input + non-terminal  ⇒ IN FLIGHT (returned immediately; never waits)
 *   different tool, version OR input                      ⇒ CONFLICT  (the stored invocation stays authoritative)
 * A slot therefore holds exactly one invocation and at most one execution,
 * whatever tool a later plan proposes for it. None of the three outcomes appends
 * an event, writes an audit entry, writes a usage row or executes anything, and
 * no replacement key is ever minted.
 *
 * TRANSITION — every persisted move locks the projection `FOR UPDATE`, checks
 * the code transition table, bumps `version`, and appends exactly ONE event
 * with `seq = version`, in the same transaction. A terminal move also writes
 * the single audit entry and, when the invocation actually entered `running`,
 * the single usage row — same transaction, so the record of what happened can
 * never disagree with the state.
 *
 * Because a terminal state accepts no transition, the settlement is exactly
 * once: whoever loses the race (the original worker or the stale-running
 * sweeper) is refused by the table and writes nothing at all.
 */
final class ToolInvocationStore
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UsageRecorder $usage,
    ) {}

    // ---------------------------------------------------------------- claim

    public function claim(ToolCallRequest $request): ToolClaim
    {
        return DB::transaction(function () use ($request): ToolClaim {
            $existing = $this->locked($request->key->value);

            if ($existing !== null) {
                return $this->observe($existing, $request);
            }

            try {
                return ToolClaim::claimed(DB::transaction(fn (): ToolInvocation => $this->create($request)));
            } catch (UniqueConstraintViolationException) {
                // A concurrent process claimed the same identity first; it owns the execution.
                $winner = $this->locked($request->key->value);

                if ($winner === null) {
                    // Unreachable: the unique index only fires when the row exists.
                    throw ToolRuleException::of('idempotency_key', 'تعذّر قراءة الاستدعاء الفائز بعد تصادم الهوية.');
                }

                return $this->observe($winner, $request);
            }
        });
    }

    /**
     * An existing slot, read and reported — never changed.
     *
     * All three claim facts must match. A different tool at the slot, a
     * different version of the same tool, or a different canonical input are
     * each a CONFLICT: the invocation already recorded there stays
     * authoritative and nothing runs a second time.
     */
    private function observe(ToolInvocation $row, ToolCallRequest $request): ToolClaim
    {
        $sameTool = $row->tool_key === $request->definition->key->name
            && $row->tool_version === $request->definition->key->version->value;

        if (! $sameTool || ! hash_equals($row->input_hash, $request->input->hash)) {
            return ToolClaim::conflict($row);
        }

        return $row->status->isTerminal() ? ToolClaim::replay($row) : ToolClaim::inFlight($row);
    }

    private function create(ToolCallRequest $request): ToolInvocation
    {
        $now = CarbonImmutable::now('UTC');

        $row = ToolInvocation::query()->create([
            'subscriber_id' => $request->subscriber->getKey(),
            'tool_key' => $request->definition->key->name,
            'tool_version' => $request->definition->key->version->value,
            'capability' => $request->definition->capability->value,
            'side_effect' => $request->definition->sideEffect->value,
            'idempotency_key' => $request->key->value,
            'input_hash' => $request->input->hash,
            // Raw arguments are never stored: only what the per-field policy
            // explicitly allows, plus the NAMES of the fields that were present.
            'input' => ToolInputPersistence::filter($request->definition->key, $request->input->values),
            'input_fields' => ToolInputPersistence::fields($request->input->values),
            'status' => ToolInvocationTransitions::initial()->value,
            'message_id' => $request->message->getKey(),
            'conversation_id' => $request->message->conversation_id,
            'call_index' => $request->callIndex,
            'version' => 1,
        ]);

        $this->appendEvent($row, null, ToolInvocationTransitions::initial(), 1, 'claimed', $now, [
            'input_hash' => $request->input->hash,
            'call_index' => $request->callIndex,
        ]);

        return $row;
    }

    // ----------------------------------------------------------- lifecycle

    /** planned → authorized. Consent has just been read and it holds. */
    public function authorize(ToolInvocation $row): ToolInvocation
    {
        return $this->transition($row, ToolInvocationStatus::Authorized, reasonCode: 'consent_granted');
    }

    /**
     * authorized → running, but ONLY if consent still holds at this instant.
     * The check runs inside the very transaction that writes `running`, so a
     * revocation that commits in between is never overtaken: the invocation
     * goes to `refused(consent_revoked)` and nothing executes.
     *
     * @param  Closure(): bool  $stillConsented
     */
    public function begin(ToolInvocation $row, Closure $stillConsented): ToolInvocation
    {
        return DB::transaction(function () use ($row, $stillConsented): ToolInvocation {
            if (! $stillConsented()) {
                return $this->refuse($row, ToolInvocationRefusalReason::ConsentRevoked);
            }

            return $this->transition($row, ToolInvocationStatus::Running, [
                'started_at' => CarbonImmutable::now('UTC'),
            ], reasonCode: 'consent_granted');
        });
    }

    /** planned|authorized → refused. Terminal: audited, and never a usage row. */
    public function refuse(ToolInvocation $row, ToolInvocationRefusalReason $reason): ToolInvocation
    {
        return $this->transition($row, ToolInvocationStatus::Refused, [
            'refusal_reason' => $reason->value,
            'finished_at' => CarbonImmutable::now('UTC'),
        ], reasonCode: $reason->value);
    }

    /** running → succeeded, with the schema-validated output of this tool version. */
    public function succeed(ToolInvocation $row, array $output, int $durationMs): ToolInvocation
    {
        return $this->settle($row, ToolInvocationStatus::Succeeded, $durationMs, ['output' => $output], 'ok');
    }

    /**
     * running → succeeded, with a LOCAL DOMAIN MUTATION committed in the very
     * same database transaction (Phase F3-V1).
     *
     * `$mutate` performs the domain write and returns the tool's output. It runs
     * inside this transaction, before the projection moves, so:
     *   - commit    ⇒ the domain row AND the `succeeded` invocation both exist;
     *   - rollback  ⇒ NEITHER exists — a crash, a domain refusal, or losing the
     *     settlement race to another process all leave the domain table exactly
     *     as it was, and the invocation simply stays `running` for the sweeper.
     *
     * That is what makes a local write EXACTLY ONCE rather than at-most-once.
     * It holds ONLY because the mutation is in the same database as the
     * invocation record. It must never be assumed for an HTTP API, a payment
     * gateway, a WhatsApp send, an email, a phone call or any other external
     * service: those cannot join this transaction and remain at-most-once.
     *
     * @param  Closure(): array<string, mixed>  $mutate  the domain write, returning the tool output
     */
    public function succeedWith(ToolInvocation $row, Closure $mutate, int $durationMs): ToolInvocation
    {
        return DB::transaction(function () use ($row, $mutate, $durationMs): ToolInvocation {
            $output = $mutate();

            return $this->settle($row, ToolInvocationStatus::Succeeded, $durationMs, ['output' => $output], 'ok');
        });
    }

    /** running → failed. */
    public function fail(ToolInvocation $row, ToolInvocationFailureKind $kind, int $durationMs): ToolInvocation
    {
        return $this->settle($row, ToolInvocationStatus::Failed, $durationMs, ['failure_kind' => $kind->value], $kind->value);
    }

    /**
     * running → timed_out. Used both by the worker that measured an overrun and
     * by the stale-running sweeper; whichever gets there first wins, and the
     * other is refused by the transition table.
     */
    public function timeOut(ToolInvocation $row, int $durationMs): ToolInvocation
    {
        return $this->settle($row, ToolInvocationStatus::TimedOut, $durationMs, [
            'failure_kind' => ToolInvocationFailureKind::Timeout->value,
        ], ToolInvocationFailureKind::Timeout->value);
    }

    // ------------------------------------------------------------ internals

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function settle(ToolInvocation $row, ToolInvocationStatus $to, int $durationMs, array $attributes, string $reasonCode): ToolInvocation
    {
        return $this->transition($row, $to, $attributes + [
            'duration_ms' => max(0, $durationMs),
            'finished_at' => CarbonImmutable::now('UTC'),
        ], reasonCode: $reasonCode);
    }

    /**
     * ONE persisted move: lock, check the code table, write the projection,
     * append exactly one event and — when the move is terminal — the single
     * audit entry and the single usage row.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(ToolInvocation $row, ToolInvocationStatus $to, array $attributes = [], ?string $reasonCode = null): ToolInvocation
    {
        return DB::transaction(function () use ($row, $to, $attributes, $reasonCode): ToolInvocation {
            $current = $this->locked($row->idempotency_key) ?? $row->refresh();
            $from = $current->status;

            ToolInvocationTransitions::assert($from, $to);

            $now = CarbonImmutable::now('UTC');
            $seq = $current->version + 1;

            $current->forceFill($attributes + ['status' => $to->value, 'version' => $seq])->save();

            $this->appendEvent($current, $from, $to, $seq, $reasonCode, $now, array_filter([
                'duration_ms' => $current->duration_ms,
            ], static fn ($v): bool => $v !== null));

            if ($to->isTerminal()) {
                $this->recordAudit($current, $from, $to);

                if ($to->consumedExecution()) {
                    $this->recordUsage($current, $to);
                }
            }

            return $current;
        });
    }

    private function locked(string $idempotencyKey): ?ToolInvocation
    {
        return ToolInvocation::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function appendEvent(ToolInvocation $row, ?ToolInvocationStatus $from, ToolInvocationStatus $to, int $seq, ?string $reasonCode, CarbonImmutable $at, array $detail = []): void
    {
        ToolInvocationEvent::query()->create([
            'tool_invocation_id' => $row->getKey(),
            'seq' => $seq,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'reason_code' => $reasonCode,
            'actor_ref' => ToolAuthorization::actorRef(),
            'detail' => $detail === [] ? null : $detail,
            'occurred_at' => $at,
            'created_at' => $at,
        ]);
    }

    /**
     * Exactly ONE audit entry per invocation — on its terminal move, not on
     * every transition (the transitions are the events table). Bounded facts
     * only: ids, codes and the input hash. Never a tool argument, never a tool
     * result, never anything personal.
     */
    private function recordAudit(ToolInvocation $row, ToolInvocationStatus $from, ToolInvocationStatus $to): void
    {
        $action = match ($to) {
            ToolInvocationStatus::Succeeded => AuditActions::ToolInvocationSucceeded,
            ToolInvocationStatus::Failed => AuditActions::ToolInvocationFailed,
            ToolInvocationStatus::TimedOut => AuditActions::ToolInvocationTimedOut,
            default => AuditActions::ToolInvocationRefused,
        };

        $this->audit->record(
            $action,
            $row,
            ['status' => ['from' => $from->value, 'to' => $to->value], 'version' => ['from' => $row->version - 1, 'to' => $row->version]],
            array_filter([
                'subscriber_id' => $row->subscriber_id,
                'tool_key' => $row->toolKeyValue(),
                'capability' => $row->capability,
                'side_effect' => $row->side_effect->value,
                'idempotency_key' => $row->idempotency_key,
                'input_hash' => $row->input_hash,
                'call_index' => $row->call_index,
                'duration_ms' => $row->duration_ms,
                'failure_kind' => $row->failure_kind?->value,
                'refusal_reason' => $row->refusal_reason?->value,
                'actor_ref' => ToolAuthorization::actorRef(),
            ], static fn ($v): bool => $v !== null),
        );
    }

    /**
     * ONE usage row for an invocation that entered `running`, linked to the
     * invocation's own persisted identity. The ledger's unique
     * `idempotency_key` makes a second row impossible even if this were ever
     * reached twice; no financial column and no ledger schema is touched.
     */
    private function recordUsage(ToolInvocation $row, ToolInvocationStatus $to): void
    {
        $subscriber = $row->subscriber()->first();

        if ($subscriber === null) {
            return; // the account is gone; the invocation history stays, the ledger gains nothing
        }

        $this->usage->record(new UsageRecord(
            subscriber: $subscriber,
            dimension: UsageDimension::ToolAction,
            idempotencyKey: 'tool_invocation:'.$row->getKey(),
            correlationId: $row->message_id === null ? null : 'message:'.$row->message_id,
            operation: 'tool:'.$row->toolKeyValue(),
            quantity: 1,
            durationMs: $row->duration_ms,
            outcome: $to === ToolInvocationStatus::Succeeded ? UsageEventOutcome::Succeeded : UsageEventOutcome::DownstreamFailed,
            occurredAt: CarbonImmutable::now('UTC'),
            toolInvocationRef: $row->ledgerRef(),
        ));
    }
}
