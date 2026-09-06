<?php

declare(strict_types=1);

use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Livewire\Dashboard\Finance\PeriodClose;
use App\Models\FinancePeriodClose;
use App\Models\User;
use App\Services\Close\PeriodCloseService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E5.1 route inventory — every surface touched by E5.1 with its
 * permission, its read-only proof and its UTC label:
 *
 *  | surface                | route                            | permission     |
 *  | finance overview       | dashboard.finance                | finance.view   |
 *  | close history          | dashboard.finance.close          | finance.view   |
 *  | close detail (new)     | dashboard.finance.close.show     | finance.view   |
 *  | cash CSV (new)         | dashboard.finance.cash.export    | finance.export |
 *  | cost CSV (new)         | dashboard.finance.cost.export    | finance.export |
 *  | fx CSV (new)           | dashboard.finance.fx.export      | finance.export |
 *  | close CSV (new)        | dashboard.finance.close.export   | finance.export |
 *  | calculated CSV (D2)    | dashboard.finance.export         | finance.export |
 *  | audit subject filter   | dashboard.audit (query params)   | audit.view     |
 *
 * finance.view alone never downloads a CSV; finance.export grants no write
 * capability; operations / support / legacy is_admin get 403 everywhere.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function inventory(int $closeId, int $scopeId): array
{
    return [
        'finance overview' => ['url' => route('dashboard.finance', ['from' => '2026-08-01', 'to' => '2026-08-31']), 'permission' => 'finance.view', 'utc' => 'Window (UTC)'],
        'close history' => ['url' => route('dashboard.finance.close', ['month' => '2026-08']), 'permission' => 'finance.view', 'utc' => 'Closed at (UTC)'],
        'close detail' => ['url' => route('dashboard.finance.close.show', $closeId), 'permission' => 'finance.view', 'utc' => 'Period (UTC, half-open)'],
        'cash CSV' => ['url' => route('dashboard.finance.cash.export', ['from' => '2026-08-01', 'to' => '2026-08-31']), 'permission' => 'finance.export', 'utc' => 'meta,timezone,UTC'],
        'cost CSV' => ['url' => route('dashboard.finance.cost.export', ['from' => '2026-08', 'to' => '2026-08']), 'permission' => 'finance.export', 'utc' => 'meta,timezone,UTC'],
        'fx CSV' => ['url' => route('dashboard.finance.fx.export', ['from' => '2026-08-01', 'to' => '2026-08-31']), 'permission' => 'finance.export', 'utc' => 'meta,timezone,UTC'],
        'close CSV' => ['url' => route('dashboard.finance.close.export', $closeId), 'permission' => 'finance.export', 'utc' => 'meta,timezone,UTC'],
        'calculated CSV (D2)' => ['url' => route('dashboard.finance.export', ['from' => '2026-08-01', 'to' => '2026-08-31']), 'permission' => 'finance.export', 'utc' => 'meta,timezone,UTC'],
        'audit subject filter' => ['url' => route('dashboard.audit', ['subject_type' => 'FinancePeriodCloseScope', 'subject_id' => $scopeId]), 'permission' => 'audit.view', 'utc' => 'الوقت (UTC)'],
    ];
}

/** A finance user whose role lost the given permissions (the matrix is re-synced by userWithRole, so revoke after creating). */
function financeWithout(array $permissions): User
{
    $user = userWithRole(Role::Finance);
    $role = SpatieRole::findByName(Role::Finance->value);
    foreach ($permissions as $permission) {
        $role->revokePermissionTo($permission);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function body(TestResponse $response): string
{
    return str_contains((string) $response->headers->get('Content-Type'), 'text/csv') ? $response->streamedContent() : $response->getContent();
}

it('every E5.1 surface: the named permission opens it, finance and super_admin pass, operations / support / legacy admin / no-role get 403, guests are redirected, and the UTC label is present', function () {
    rbacSync();
    closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    $items = inventory($close->id, $close->scope_id);

    foreach ($items as $name => $item) {
        $this->get($item['url'])->assertRedirect(route('login'));
    }

    $legacy = User::factory()->create(['is_admin' => true]);
    $none = User::factory()->create(['is_admin' => false]);
    $operations = userWithRole(Role::Operations);
    $support = userWithRole(Role::Support);
    $finance = userWithRole(Role::Finance);
    $super = userWithRole(Role::SuperAdmin);

    foreach ($items as $name => $item) {
        $this->actingAs($legacy)->get($item['url'])->assertForbidden();
        $this->actingAs($none)->get($item['url'])->assertForbidden();
        $this->actingAs($operations)->get($item['url'])->assertForbidden();
        $this->actingAs($support)->get($item['url'])->assertForbidden();
        expect(str_contains(body($this->actingAs($finance)->get($item['url'])->assertOk()), $item['utc']))->toBeTrue($name.' UTC label');
        $this->actingAs($super)->get($item['url'])->assertOk();
    }
});

it('finance.view alone never downloads a CSV (all five export routes 403), and audit.view is required for the audit subject filter even with finance.view', function () {
    closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    $items = inventory($close->id, $close->scope_id);
    $viewer = financeWithout(['finance.export', 'audit.view']);

    foreach (['cash CSV', 'cost CSV', 'fx CSV', 'close CSV', 'calculated CSV (D2)', 'audit subject filter'] as $name) {
        $this->actingAs($viewer)->get($items[$name]['url'])->assertForbidden();
    }
    foreach (['finance overview', 'close history', 'close detail'] as $name) {
        $this->actingAs($viewer)->get($items[$name]['url'])->assertOk()->assertDontSee('data-testid="audit-link"', false)->assertDontSee('/finance/cash/export');
    }
});

it('finance.export grants no write capability: with finance.view + finance.export only, every finance write surface and service refuses, and the exports themselves write nothing', function () {
    closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    $exporter = financeWithout(['finance.payments.manage', 'finance.reconcile', 'finance.fx.manage']);
    expect($exporter->can('finance.export'))->toBeTrue()->and($exporter->can('finance.view'))->toBeTrue()->and($exporter->can('finance.close_period'))->toBeFalse();

    $this->actingAs($exporter);
    $this->get(route('dashboard.finance.payments'))->assertForbidden();
    $this->get(route('dashboard.finance.reconciliation'))->assertForbidden();
    $this->get(route('dashboard.finance.fx'))->assertForbidden();
    Livewire::actingAs($exporter)->test(PeriodClose::class)->set('month', '2026-08')->set('reopenCloseId', (string) $close->id)->set('reopenTyped', 'REOPEN 2026-08')->set('reopenReason', 'x')->set('reopenEvidence', 'y')->call('reopen')->assertForbidden();
    expect(fn () => app(PeriodCloseService::class)->reopen($close->id, $close->id, 'x', 'y', 'REOPEN 2026-08'))->toThrow(AuthorizationException::class)
        ->and(fn () => $close->forceFill(['reconciled_cash_contribution' => '1'])->save())->toThrow(ImmutableFinancialRecordException::class);

    $writes = [];
    DB::listen(function (QueryExecuted $q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql) === 1) {
            $writes[] = $q->sql;
        }
    });
    foreach (inventory($close->id, $close->scope_id) as $name => $item) {
        if (str_contains($name, 'CSV')) {
            $this->get($item['url'])->assertOk()->streamedContent();
        }
    }
    expect($writes)->toBe([])->and(FinancePeriodClose::count())->toBe(1);
});
