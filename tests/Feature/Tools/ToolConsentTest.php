<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolConsentStatus;
use App\Exceptions\Tools\StaleToolConsentException;
use App\Exceptions\Tools\ToolRuleException;
use App\Models\AuditLog;
use App\Models\ToolConsent;
use App\Models\User;
use App\Services\Tools\ToolConsentService;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase F1 — subscriber capability consent: never implied, explicitly granted,
 * immediately revocable, stale-safe, audited exactly once, and free of personal
 * data. It is INDEPENDENT of operator RBAC in both directions.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00', 'UTC'));
});

function consents(): ToolConsentService
{
    return app(ToolConsentService::class);
}

/** The audit rows of one consent row, newest last. */
function consentAudits(ToolConsent $row)
{
    return AuditLog::query()->where('subject_type', $row->getMorphClass())->where('subject_id', $row->id)->orderBy('id')->get();
}

it('reads NOT GRANTED with version 0 when nothing was ever decided — no row, no default, no inheritance', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (ToolCapability::cases() as $capability) {
        $state = consents()->state($user->id, $capability);

        expect($state->status)->toBe(ToolConsentStatus::NOT_GRANTED)
            ->and($state->version)->toBe(0)
            ->and($state->granted())->toBeFalse()
            ->and(consents()->granted($user->id, $capability))->toBeFalse();
    }

    expect(ToolConsent::count())->toBe(0);
});

it('grants, then revokes, moving the version once each time and stamping UTC moments', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $granted = consents()->grant($user->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest, 'ticket:441');

    expect($granted->status)->toBe(ToolConsentStatus::Granted)->and($granted->version)->toBe(1)
        ->and($granted->granted_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-07 12:00:00')
        ->and($granted->revoked_at)->toBeNull()
        ->and($granted->updated_by_ref)->toBe('user:'.$user->id)
        ->and(consents()->granted($user->id, ToolCapability::TasksWrite))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-09-07 13:00:00', 'UTC'));
    $revoked = consents()->revoke($user->id, ToolCapability::TasksWrite, 1, ToolConsentReason::Security, 'policy:review');

    expect($revoked->is($granted))->toBeTrue()
        ->and($revoked->status)->toBe(ToolConsentStatus::Revoked)->and($revoked->version)->toBe(2)
        ->and($revoked->granted_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-07 12:00:00') // when it was granted stays recorded
        ->and($revoked->revoked_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-07 13:00:00')
        // A revocation is effective for the very next authorization read — nothing is cached or grandfathered.
        ->and(consents()->granted($user->id, ToolCapability::TasksWrite))->toBeFalse()
        ->and(ToolConsent::count())->toBe(1);

    // Granting again is a new decision on the same row.
    $again = consents()->grant($user->id, ToolCapability::TasksWrite, 2, ToolConsentReason::SubscriberRequest);
    expect($again->version)->toBe(3)->and(consents()->granted($user->id, ToolCapability::TasksWrite))->toBeTrue();
});

it('refuses a stale mutation and an unchanged one, writing nothing and auditing nothing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    consents()->grant($user->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    $row = ToolConsent::query()->firstOrFail();
    $audits = consentAudits($row)->count();

    // The version the caller saw is not the current one.
    expect(fn () => consents()->revoke($user->id, ToolCapability::MemoryRead, 0, ToolConsentReason::Security))->toThrow(StaleToolConsentException::class, 'المتوقع نسخة 0')
        // Claiming there is no row when there is one.
        ->and(fn () => consents()->grant($user->id, ToolCapability::MemoryRead, 0, ToolConsentReason::Policy))->toThrow(StaleToolConsentException::class)
        // A no-op mutation is refused rather than recorded as a decision.
        ->and(fn () => consents()->grant($user->id, ToolCapability::MemoryRead, 1, ToolConsentReason::Policy))->toThrow(ToolRuleException::class, 'granted بالفعل')
        // Stating a version for a capability that has no row at all.
        ->and(fn () => consents()->revoke($user->id, ToolCapability::RemindersWrite, 3, ToolConsentReason::Security))->toThrow(StaleToolConsentException::class, 'لا توجد موافقة');

    $row->refresh();
    expect($row->version)->toBe(1)->and($row->status)->toBe(ToolConsentStatus::Granted)
        ->and(consentAudits($row)->count())->toBe($audits)
        ->and(ToolConsent::count())->toBe(1);
});

it('writes exactly one audit entry per mutation, in the same transaction, with bounded facts only', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $row = consents()->grant($user->id, ToolCapability::RemindersWrite, 0, ToolConsentReason::SubscriberRequest, 'ticket:9');
    consents()->revoke($user->id, ToolCapability::RemindersWrite, 1, ToolConsentReason::Expired, 'policy:90d');

    $audits = consentAudits($row);

    expect($audits)->toHaveCount(2)
        ->and($audits[0]->action)->toBe(AuditActions::ToolConsentGranted)
        ->and($audits[0]->metadata['changes']['status'])->toBe(['from' => ToolConsentStatus::NOT_GRANTED, 'to' => 'granted'])
        ->and($audits[0]->metadata['changes']['version'])->toBe(['from' => 0, 'to' => 1])
        ->and($audits[0]->metadata['context'])->toBe(['subscriber_id' => $user->id, 'capability' => 'reminders.write', 'reason_code' => 'subscriber_request', 'evidence_ref' => 'ticket:9'])
        ->and($audits[0]->actor_ref)->toBe('user:'.$user->id)
        ->and($audits[1]->action)->toBe(AuditActions::ToolConsentRevoked)
        ->and($audits[1]->metadata['changes']['status'])->toBe(['from' => 'granted', 'to' => 'revoked'])
        ->and($audits[1]->metadata['context']['reason_code'])->toBe('expired');

    // The audit is part of the same transaction: a failing audit rolls the consent back.
    $before = ToolConsent::query()->firstOrFail()->version;
    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'insert into "audit_logs"')) {
            throw new RuntimeException('audit exploded');
        }
    });

    try {
        consents()->grant($user->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest);
    } catch (Throwable) {
        // expected
    }

    expect(ToolConsent::query()->where('capability', 'tasks.write')->count())->toBe(0)
        ->and(ToolConsent::query()->where('capability', 'reminders.write')->firstOrFail()->version)->toBe($before);
});

it('keeps NO personal data anywhere on the row or in its audit: closed reason codes and a screened evidence ref', function () {
    $user = User::factory()->create(['name' => 'Omar Test', 'email' => 'omar.test@example.com', 'phone' => '+970599123456']);
    $this->actingAs($user);

    // An email, a phone number or free text can never become "evidence".
    foreach (['omar.test@example.com', '+970599123456', '0599123456', 'called him on 0599123456', "line\nbreak"] as $pii) {
        expect(fn () => consents()->grant($user->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest, $pii))
            ->toThrow(ToolRuleException::class);
    }

    $row = consents()->grant($user->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest, 'ticket:12');
    $serialized = json_encode($row->toArray(), JSON_UNESCAPED_UNICODE).json_encode(consentAudits($row)->toArray(), JSON_UNESCAPED_UNICODE);

    foreach ([$user->name, $user->email, $user->phone, '0599123456'] as $pii) {
        expect(str_contains($serialized, (string) $pii))->toBeFalse('consent must not carry '.$pii);
    }

    // Reason codes are a closed enum — there is no free-text field to type into.
    expect($row->reason_code)->toBeInstanceOf(ToolConsentReason::class)
        ->and(fn () => consents()->revoke($user->id, ToolCapability::TasksWrite, 1, ToolConsentReason::Security, str_repeat('a', 200)))->toThrow(ToolRuleException::class, '191');
});

it('is INDEPENDENT of operator RBAC: a permission grants no consent, and consent grants no permission', function () {
    rbacSync();
    $subscriber = User::factory()->create();
    $operator = userWithRole(Role::SuperAdmin); // holds subscribers.manage

    // The operator may administer the subscriber's consent…
    $this->actingAs($operator);
    consents()->grant($subscriber->id, ToolCapability::TasksWrite, 0, ToolConsentReason::OperatorRequest, 'ticket:77');

    expect(consents()->granted($subscriber->id, ToolCapability::TasksWrite))->toBeTrue()
        // …but the operator's own consent is untouched: holding the permission is not consent.
        ->and(consents()->granted($operator->id, ToolCapability::TasksWrite))->toBeFalse()
        ->and(consents()->state($operator->id, ToolCapability::TasksWrite)->status)->toBe(ToolConsentStatus::NOT_GRANTED);

    // And the subscriber's consent grants them no permission at all.
    expect($subscriber->fresh()->can(ToolCapability::TasksWrite->operatorPermission()->value))->toBeFalse()
        ->and($subscriber->fresh()->can('dashboard.access'))->toBeFalse();

    // A user with no permission cannot administer someone else's consent, but may decide for themself.
    $this->actingAs($subscriber);
    expect(fn () => consents()->grant($operator->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest))->toThrow(AuthorizationException::class);
    consents()->grant($subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    expect(consents()->granted($subscriber->id, ToolCapability::MemoryRead))->toBeTrue();

    // An operations user (no subscribers.manage) is refused for someone else's consent.
    $this->actingAs(userWithRole(Role::Operations));
    expect(fn () => consents()->revoke($subscriber->id, ToolCapability::MemoryRead, 1, ToolConsentReason::Security))->toThrow(AuthorizationException::class);
    expect(consents()->granted($subscriber->id, ToolCapability::MemoryRead))->toBeTrue();
});

it('refuses a consent for a subscriber that does not exist, and a negative expected version', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(toolRule(fn () => consents()->grant($user->id, ToolCapability::TasksWrite, -1, ToolConsentReason::Policy)))->toBe('expected_version')
        ->and(ToolConsent::count())->toBe(0);

    $this->actingAs(userWithRole(Role::SuperAdmin));
    expect(toolRule(fn () => consents()->grant(999999, ToolCapability::TasksWrite, 0, ToolConsentReason::OperatorRequest)))->toBe('subscriber')
        ->and(ToolConsent::count())->toBe(0);
});
