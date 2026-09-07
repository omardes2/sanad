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
use App\Support\Tools\EvidenceRef;
use App\Support\Tools\ToolAuthorization;
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

    $granted = consents()->grant($user->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest, EvidenceRef::of('message:441'));

    expect($granted->status)->toBe(ToolConsentStatus::Granted)->and($granted->version)->toBe(1)
        ->and($granted->granted_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-07 12:00:00')
        ->and($granted->revoked_at)->toBeNull()
        ->and($granted->updated_by_ref)->toBe('user:'.$user->id)
        ->and(consents()->granted($user->id, ToolCapability::TasksWrite))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-09-07 13:00:00', 'UTC'));
    $revoked = consents()->revoke($user->id, ToolCapability::TasksWrite, 1, ToolConsentReason::Security, EvidenceRef::of('policy:review'));

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

    $row = consents()->grant($user->id, ToolCapability::RemindersWrite, 0, ToolConsentReason::SubscriberRequest, EvidenceRef::of('conversation:9'));
    consents()->revoke($user->id, ToolCapability::RemindersWrite, 1, ToolConsentReason::Expired, EvidenceRef::of('policy:retention-90d'));

    $audits = consentAudits($row);

    expect($audits)->toHaveCount(2)
        ->and($audits[0]->action)->toBe(AuditActions::ToolConsentGranted)
        ->and($audits[0]->metadata['changes']['status'])->toBe(['from' => ToolConsentStatus::NOT_GRANTED, 'to' => 'granted'])
        ->and($audits[0]->metadata['changes']['version'])->toBe(['from' => 0, 'to' => 1])
        ->and($audits[0]->metadata['context'])->toBe(['subscriber_id' => $user->id, 'capability' => 'reminders.write', 'reason_code' => 'subscriber_request', 'evidence_ref' => 'conversation:9', 'actor_ref' => 'user:'.$user->id])
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

it('keeps NO personal data anywhere on the row or in its audit: closed reason codes and an OPAQUE evidence reference', function () {
    $user = User::factory()->create(['name' => 'Omar Test', 'email' => 'omar.test@example.com', 'phone' => '+970599123456']);
    $this->actingAs($user);

    // Evidence is a closed grammar, not a filtered free-text field: prose, names, emails, phones,
    // message text and malformed or unknown prefixes are all refused before anything is written.
    foreach ([
        'Omar Test',
        'omar.test@example.com',
        '+970599123456',
        '0599123456',
        'granted after he called me about the reminders',
        'ticket:12',                       // an unknown prefix is not "close enough"
        'message:',                        // empty value
        'message:abc',                     // not an id
        'message: 12',                     // whitespace
        'MESSAGE:12',                      // upper case
        'policy:GDPR',                     // policy codes are lower-case
        'policy:سياسة',                    // no unicode names
        'message:12:extra',
        'conversation',
        str_repeat('a', 70),
    ] as $refused) {
        expect(fn () => EvidenceRef::of($refused))->toThrow(ToolRuleException::class, 'مرجع الدليل');
        expect(toolRule(fn () => consents()->grant($user->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest, EvidenceRef::of($refused))))->toBe('evidence_ref');
    }

    expect(ToolConsent::count())->toBe(0);

    // The four allowlisted machine references are accepted and stored verbatim.
    foreach (['message:12', 'conversation:9', 'admin_action:7', 'policy:gdpr-erasure'] as $accepted) {
        expect(EvidenceRef::of($accepted)->value())->toBe($accepted);
    }

    $row = consents()->grant($user->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest, EvidenceRef::of('message:12'));
    expect($row->evidence_ref)->toBe('message:12');

    // …and no reference at all is stored as NULL rather than invented text.
    $none = consents()->grant($user->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    expect($none->evidence_ref)->toBeNull();

    $serialized = json_encode([$row->toArray(), $none->toArray(), consentAudits($row)->toArray(), consentAudits($none)->toArray()], JSON_UNESCAPED_UNICODE);

    foreach ([$user->name, $user->email, $user->phone, '0599123456', 'Omar'] as $pii) {
        expect(str_contains($serialized, (string) $pii))->toBeFalse('consent must not carry '.$pii);
    }

    expect($row->reason_code)->toBeInstanceOf(ToolConsentReason::class);
});

it('is INDEPENDENT of operator RBAC: a permission grants no consent, and consent grants no permission', function () {
    rbacSync();
    $subscriber = User::factory()->create();
    $operator = userWithRole(Role::SuperAdmin); // holds subscribers.manage

    // Only the subscriber can create their own consent — an operator cannot, whatever they hold.
    $this->actingAs($operator);
    expect(fn () => consents()->grant($subscriber->id, ToolCapability::TasksWrite, 0, ToolConsentReason::OperatorRequest, EvidenceRef::of('admin_action:77')))
        ->toThrow(AuthorizationException::class, 'only by the subscriber themself');
    expect(ToolConsent::count())->toBe(0);

    $this->actingAs($subscriber);
    consents()->grant($subscriber->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest);

    expect(consents()->granted($subscriber->id, ToolCapability::TasksWrite))->toBeTrue()
        // The operator's own consent is untouched: holding the permission is not consent.
        ->and(consents()->granted($operator->id, ToolCapability::TasksWrite))->toBeFalse()
        ->and(consents()->state($operator->id, ToolCapability::TasksWrite)->status)->toBe(ToolConsentStatus::NOT_GRANTED);

    // And the subscriber's consent grants them no permission at all.
    expect($subscriber->fresh()->can(ToolCapability::TasksWrite->operatorPermission()->value))->toBeFalse()
        ->and($subscriber->fresh()->can('dashboard.access'))->toBeFalse();

    // The operator MAY reduce authority: revocation is the one staff action.
    $this->actingAs($operator);
    $revoked = consents()->revoke($subscriber->id, ToolCapability::TasksWrite, 1, ToolConsentReason::Security, EvidenceRef::of('admin_action:77'));

    expect($revoked->status)->toBe(ToolConsentStatus::Revoked)
        ->and($revoked->updated_by_ref)->toBe('user:'.$operator->id)
        ->and(consents()->granted($subscriber->id, ToolCapability::TasksWrite))->toBeFalse();

    // …but cannot put it back.
    expect(fn () => consents()->grant($subscriber->id, ToolCapability::TasksWrite, 2, ToolConsentReason::OperatorRequest))
        ->toThrow(AuthorizationException::class);

    // An operations user (no subscribers.manage) may not even revoke.
    $this->actingAs($subscriber);
    consents()->grant($subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    $this->actingAs(userWithRole(Role::Operations));
    expect(fn () => consents()->revoke($subscriber->id, ToolCapability::MemoryRead, 1, ToolConsentReason::Security))->toThrow(AuthorizationException::class)
        ->and(consents()->granted($subscriber->id, ToolCapability::MemoryRead))->toBeTrue();
});

it('lets NO ONE but the subscriber create consent: not another subscriber, not an operator, not the console, not an unauthenticated job', function () {
    rbacSync();
    $subscriber = User::factory()->create();
    $other = User::factory()->create();
    $operator = userWithRole(Role::SuperAdmin);

    // another subscriber
    $this->actingAs($other);
    expect(fn () => consents()->grant($subscriber->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest))->toThrow(AuthorizationException::class);

    // an operator with every finance/subscriber permission
    $this->actingAs($operator);
    expect(fn () => consents()->grant($subscriber->id, ToolCapability::TasksWrite, 0, ToolConsentReason::OperatorRequest))->toThrow(AuthorizationException::class);

    // an unauthenticated context (a queued job, a provider callback, the model itself)
    auth()->forgetUser();
    expect(fn () => consents()->grant($subscriber->id, ToolCapability::TasksWrite, 0, ToolConsentReason::Policy))->toThrow(AuthorizationException::class)
        // …and the same context may not revoke either, unless it declares itself an administrator
        ->and(fn () => consents()->revoke($subscriber->id, ToolCapability::TasksWrite, 0, ToolConsentReason::Security))->toThrow(AuthorizationException::class, 'must declare itself an administrator');

    // even INSIDE the administrator scope a grant is refused: staff reduce authority, never create it
    expect(fn () => ToolAuthorization::asConsoleAdministrator('ops.consent-revocation', fn () => consents()->grant($subscriber->id, ToolCapability::TasksWrite, 0, ToolConsentReason::Policy)))
        ->toThrow(AuthorizationException::class, 'only by the subscriber themself');

    expect(ToolConsent::count())->toBe(0);

    // The subscriber themself is the one path that works.
    $this->actingAs($subscriber);
    expect(consents()->grant($subscriber->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest)->version)->toBe(1);
});

it('lets a NAMED console administrator revoke — never an anonymous console — and records that actor on the row and in the audit', function () {
    $subscriber = User::factory()->create();
    $this->actingAs($subscriber);
    $row = consents()->grant($subscriber->id, ToolCapability::RemindersWrite, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();

    // The scope must be named with a bounded reference, and it exists only in the console.
    expect(fn () => ToolAuthorization::asConsoleAdministrator('', fn () => null))->toThrow(AuthorizationException::class, 'bounded reference')
        ->and(fn () => ToolAuthorization::asConsoleAdministrator('bad ref with spaces', fn () => null))->toThrow(AuthorizationException::class);

    $revoked = ToolAuthorization::asConsoleAdministrator('ops.consent-revocation', fn () => consents()->revoke($subscriber->id, ToolCapability::RemindersWrite, 1, ToolConsentReason::Security, EvidenceRef::of('policy:incident-42')));

    expect($revoked->status)->toBe(ToolConsentStatus::Revoked)
        ->and($revoked->updated_by_ref)->toBe('console_admin:ops.consent-revocation')
        ->and(consentAudits($row)->last()->metadata['context']['actor_ref'])->toBe('console_admin:ops.consent-revocation')
        ->and(consentAudits($row)->last()->metadata['context']['evidence_ref'])->toBe('policy:incident-42');

    // Outside the scope the same console run is refused again — the scope does not leak.
    expect(fn () => consents()->revoke($subscriber->id, ToolCapability::RemindersWrite, 2, ToolConsentReason::Security))->toThrow(AuthorizationException::class);
});

it('has no other writer of tool_consents in the application: every mutation goes through the service contract', function () {
    $writers = [];

    foreach (array_merge(glob(app_path('*/*.php')), glob(app_path('*/*/*.php')), glob(app_path('*/*/*/*.php'))) as $file) {
        $source = php_strip_whitespace($file);

        if (preg_match('/ToolConsent::query\(\)->(create|update|delete)|ToolConsent::(create|insert)|DB::table\([\'"]tool_consents/', $source) === 1) {
            $writers[] = str_replace(app_path().'/', '', $file);
        }
    }

    expect($writers)->toBe(['Services/Tools/ToolConsentService.php']);
});

it('refuses a negative expected version, and a consent for a subscriber that does not exist', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(toolRule(fn () => consents()->grant($user->id, ToolCapability::TasksWrite, -1, ToolConsentReason::Policy)))->toBe('expected_version')
        ->and(ToolConsent::count())->toBe(0);

    // Nobody can grant for a missing subscriber (there is no authenticated one to be them); a named
    // console administrator reaches the rule on the revoke path and is refused there.
    auth()->forgetUser();
    expect(toolRule(fn () => ToolAuthorization::asConsoleAdministrator('ops.consent-revocation', fn () => consents()->revoke(999999, ToolCapability::TasksWrite, 0, ToolConsentReason::Security))))->toBe('subscriber')
        ->and(ToolConsent::count())->toBe(0);
});
