<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\Tools\ToolConsentState;
use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolConsentStatus;
use App\Exceptions\Tools\StaleToolConsentException;
use App\Exceptions\Tools\ToolRuleException;
use App\Models\ToolConsent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Tools\ToolAuthorization;
use App\Support\Tools\ToolRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of `tool_consents` (Phase F1) — durable subscriber capability
 * consent, granted and revoked explicitly.
 *
 * Reading — `state()` / `granted()`: a capability with no row reads as NOT
 * GRANTED with version 0. Consent is never implied by a role, a plan, a
 * previous message or another capability.
 *
 * Writing — `grant()` / `revoke()`: authorization first (the subscriber
 * themself or an operator holding the capability's allowlisted permission),
 * then ONE transaction that
 *   - locks the row `FOR UPDATE` (the concurrency point),
 *   - refuses a caller whose `expectedVersion` is not the current one (stale:
 *     nothing written, no audit),
 *   - refuses a mutation that would not change the status (`unchanged`),
 *   - moves the status, stamps the UTC moment, bumps the version, and
 *   - writes exactly ONE audit entry — in the same transaction, so an audit
 *     failure rolls the consent change back.
 *
 * The FIRST write has no row to lock: it inserts inside a savepoint and the
 * unique index on (subscriber, capability) decides the race — the loser waits
 * for the winner's commit and is then refused as stale. Two concurrent first
 * grants therefore produce one row, one audit and one winner.
 *
 * A revocation takes effect the moment it commits: every later authorization
 * check reads the current row, so nothing is cached and nothing is grandfathered.
 */
final class ToolConsentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The current decision, or NOT GRANTED with version 0 when there is none. */
    public function state(int $subscriberId, ToolCapability $capability): ToolConsentState
    {
        $row = ToolConsent::query()->where('subscriber_id', $subscriberId)->where('capability', $capability->value)->first();

        return $row === null
            ? ToolConsentState::notGranted($subscriberId, $capability)
            : ToolConsentState::of($subscriberId, $capability, $row->status, $row->version, self::utc($row->granted_at), self::utc($row->revoked_at));
    }

    /** The one question the executor asks in F2+: may this subscriber's tools use this capability right now? */
    public function granted(int $subscriberId, ToolCapability $capability): bool
    {
        return $this->state($subscriberId, $capability)->granted();
    }

    /**
     * @throws StaleToolConsentException|ToolRuleException
     */
    public function grant(int $subscriberId, ToolCapability $capability, int $expectedVersion, ToolConsentReason $reason, ?string $evidenceRef = null): ToolConsent
    {
        return $this->mutate($subscriberId, $capability, ToolConsentStatus::Granted, $expectedVersion, $reason, $evidenceRef);
    }

    /**
     * @throws StaleToolConsentException|ToolRuleException
     */
    public function revoke(int $subscriberId, ToolCapability $capability, int $expectedVersion, ToolConsentReason $reason, ?string $evidenceRef = null): ToolConsent
    {
        return $this->mutate($subscriberId, $capability, ToolConsentStatus::Revoked, $expectedVersion, $reason, $evidenceRef);
    }

    // ------------------------------------------------------------------

    private function mutate(int $subscriberId, ToolCapability $capability, ToolConsentStatus $target, int $expectedVersion, ToolConsentReason $reason, ?string $evidenceRef): ToolConsent
    {
        ToolAuthorization::assertMayManageConsent($subscriberId, $capability);
        $subscriberId = ToolRules::subscriberId($subscriberId);
        $expectedVersion = ToolRules::expectedVersion($expectedVersion);
        $evidence = ToolRules::evidenceRef($evidenceRef);

        if (! User::query()->whereKey($subscriberId)->exists()) {
            throw ToolRuleException::of('subscriber', 'المشترك غير موجود.');
        }

        return DB::transaction(function () use ($subscriberId, $capability, $target, $expectedVersion, $reason, $evidence): ToolConsent {
            $row = $this->locked($subscriberId, $capability);

            if ($row === null) {
                if ($expectedVersion !== 0) {
                    throw new StaleToolConsentException("لا توجد موافقة مسجَّلة لهذه القدرة (المتوقع نسخة {$expectedVersion}). حدّث وأعد المحاولة. لم يُكتب شيء.");
                }

                try {
                    // First write: the unique index on (subscriber, capability) is the race arbiter.
                    return DB::transaction(fn (): ToolConsent => $this->create($subscriberId, $capability, $target, $reason, $evidence));
                } catch (UniqueConstraintViolationException) {
                    $winner = $this->locked($subscriberId, $capability);

                    throw new StaleToolConsentException('سجّل طلب متزامن آخر الموافقة أولًا (النسخة الحالية '.($winner?->version ?? 1).'). لم يُكتب شيء.');
                }
            }

            if ($row->version !== $expectedVersion) {
                throw new StaleToolConsentException("تغيّرت الموافقة (المتوقع نسخة {$expectedVersion}، الحالية {$row->version}). حدّث وأعد المحاولة. لم يُكتب شيء.");
            }

            if ($row->status === $target) {
                throw ToolRuleException::of('unchanged', "الموافقة {$target->value} بالفعل؛ لا شيء ليتغيّر.");
            }

            return $this->move($row, $target, $reason, $evidence);
        });
    }

    private function locked(int $subscriberId, ToolCapability $capability): ?ToolConsent
    {
        return ToolConsent::query()->where('subscriber_id', $subscriberId)->where('capability', $capability->value)->lockForUpdate()->first();
    }

    private function create(int $subscriberId, ToolCapability $capability, ToolConsentStatus $target, ToolConsentReason $reason, ?string $evidence): ToolConsent
    {
        $now = CarbonImmutable::now('UTC');

        $row = ToolConsent::query()->create([
            'subscriber_id' => $subscriberId,
            'capability' => $capability->value,
            'status' => $target->value,
            'granted_at' => $target === ToolConsentStatus::Granted ? $now : null,
            'revoked_at' => $target === ToolConsentStatus::Revoked ? $now : null,
            'reason_code' => $reason->value,
            'evidence_ref' => $evidence,
            'version' => 1,
            'updated_by_ref' => ToolAuthorization::actorRef(),
        ]);

        $this->record($row, ToolConsentStatus::NOT_GRANTED, $target, 0);

        return $row;
    }

    private function move(ToolConsent $row, ToolConsentStatus $target, ToolConsentReason $reason, ?string $evidence): ToolConsent
    {
        $now = CarbonImmutable::now('UTC');
        $from = $row->status;

        $row->forceFill([
            'status' => $target->value,
            'granted_at' => $target === ToolConsentStatus::Granted ? $now : $row->granted_at,
            'revoked_at' => $target === ToolConsentStatus::Revoked ? $now : $row->revoked_at,
            'reason_code' => $reason->value,
            'evidence_ref' => $evidence,
            'version' => $row->version + 1,
            'updated_by_ref' => ToolAuthorization::actorRef(),
        ])->save();

        $this->record($row, $from->value, $target, $row->version - 1);

        return $row;
    }

    /**
     * One audit entry per mutation, inside the same transaction. Bounded facts
     * only: ids, the capability, the statuses, the version and a closed reason
     * code — never a name, an email, a phone number or free text.
     */
    private function record(ToolConsent $row, string $from, ToolConsentStatus $target, int $fromVersion): void
    {
        $this->audit->record(
            $target === ToolConsentStatus::Granted ? AuditActions::ToolConsentGranted : AuditActions::ToolConsentRevoked,
            $row,
            ['status' => ['from' => $from, 'to' => $target->value], 'version' => ['from' => $fromVersion, 'to' => $row->version]],
            array_filter([
                'subscriber_id' => $row->subscriber_id,
                'capability' => $row->capability->value,
                'reason_code' => $row->reason_code->value,
                'evidence_ref' => $row->evidence_ref,
            ], static fn ($v) => $v !== null),
        );
    }

    private static function utc(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::instance($value)->utc();
    }
}
