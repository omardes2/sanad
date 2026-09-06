<?php

declare(strict_types=1);

use App\Enums\CostSource;
use App\Enums\SubscriptionStatus;
use App\Models\FinanceMrrSnapshot;
use App\Models\User;
use App\Services\Finance\FinanceQuery;
use App\Services\Finance\MrrCalculator;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase D2 — the calculated CSV: same numbers as the page (same services),
 * the mandated metadata, no gross profit figure, no PII, strict RBAC.
 */
function exportFixture(): array
{
    $usd = billingPlan(attrs: ['slug' => 'usd-monthly', 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly']);
    $alice = billingSubscriber($usd);
    billingSubscriber($usd, ['status' => SubscriptionStatus::PastDue]);

    $day = CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC');
    financeRow(['user_id' => $alice->id, 'subscriber_id' => $alice->id, 'plan_id' => $usd->id, 'plan_slug' => 'usd-monthly', 'total_cost' => '0.250000', 'provider_cost' => '0.250000', 'channel' => 'whatsapp', 'occurred_at' => $day]);
    financeRow(['user_id' => $alice->id, 'subscriber_id' => $alice->id, 'plan_id' => $usd->id, 'plan_slug' => 'usd-monthly', 'total_cost' => '0.000000', 'cost_source' => CostSource::None, 'occurred_at' => $day->addDay()]);
    financeRow(['total_cost' => '0.400000', 'provider_cost' => '0.400000', 'operation' => 'health_check', 'channel' => 'admin', 'occurred_at' => $day]);

    FinanceMrrSnapshot::create([
        'snapshot_date' => '2026-09-11', 'captured_at' => CarbonImmutable::parse('2026-09-11 03:00:00', 'UTC'), 'currency' => 'USD',
        'plan_id' => $usd->id, 'plan_key' => "plan:{$usd->id}", 'plan_slug' => 'usd-monthly', 'plan_price' => '10.00', 'billing_period' => 'monthly',
        'active_count' => 1, 'trialing_count' => 0, 'past_due_count' => 1, 'mrr_normalized' => '10.000000', 'calculation_version' => 1,
    ]);

    return [$usd, $alice];
}

function exportCsv(User $user, array $params = []): string
{
    $response = test()->actingAs($user)->get(route('dashboard.finance.export', array_merge(['from' => '2026-09-09', 'to' => '2026-09-12'], $params)));
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private');

    return $response->streamedContent();
}

function csvSection(string $csv, string $section): array
{
    $rows = [];
    foreach (explode("\n", $csv) as $line) {
        if ($line === '' || ! str_starts_with($line, $section.',')) {
            continue;
        }
        $rows[] = str_getcsv($line);
    }

    return $rows;
}

it('is downloadable only with finance.export', function () {
    rbacSync();
    $params = ['from' => '2026-09-09', 'to' => '2026-09-12'];

    $this->get(route('dashboard.finance.export', $params))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('dashboard.finance.export', $params))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard.finance.export', $params))->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard.finance.export', $params))->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.export', $params))->assertOk();
});

it('requires a valid bounded window and validates the filters', function () {
    $user = userWithRole(Role::Finance);

    $this->actingAs($user)->get(route('dashboard.finance.export'))->assertSessionHasErrors(['from', 'to']);
    $this->actingAs($user)->get(route('dashboard.finance.export', ['from' => '2026-01-01', 'to' => '2026-06-30']))->assertStatus(422);
    $this->actingAs($user)->get(route('dashboard.finance.export', ['from' => '2026-09-01', 'to' => '2026-09-02', 'attribution' => 'everyone']))->assertSessionHasErrors(['attribution']);
    $this->actingAs($user)->get(route('dashboard.finance.export', ['from' => '2026-09-01', 'to' => '2026-09-02', 'top' => 500]))->assertSessionHasErrors(['top']);
});

it('carries the mandated metadata and no gross profit figure', function () {
    exportFixture();

    $csv = exportCsv(userWithRole(Role::Finance), ['channel' => 'whatsapp']);
    $meta = collect(csvSection($csv, 'meta'))->mapWithKeys(static fn (array $r) => [$r[1] => $r[2]]);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($meta['calculated_not_collected'])->toBe('true')
        ->and($meta['timezone'])->toBe('UTC')
        ->and($meta['window_from'])->toBe('2026-09-09')
        ->and($meta['window_to'])->toBe('2026-09-12')
        ->and($meta['cost_coverage'])->toBe('provider=complete;communication=incomplete;external=no_producer')
        ->and($meta['unpriced_rows'])->toBe('0')
        ->and($meta['mrr_as_of'])->toMatch('/^\d{4}-\d{2}-\d{2}T/')
        ->and($meta['historical_revenue_available'])->toBe('false')
        ->and($meta['gross_margin_available'])->toBe('false')
        ->and($meta['gross_margin_status'])->toBe('NOT AVAILABLE — Phase E')
        ->and($meta['gross_margin_reasons'])->toContain('revenue_history_unavailable')
        ->and($meta['filter_channel'])->toBe('whatsapp')
        ->and(csvSection($csv, 'gross_margin'))->each->toHaveCount(3) // status + reason only — no amount column
        ->and($csv)->not->toContain('gross_profit')->not->toContain('Collected')->not->toContain('Reconciled');

    foreach (csvSection($csv, 'gross_margin') as $row) {
        expect($row[1])->toBe('NOT AVAILABLE — Phase E')->and(preg_match('/\d+\.\d{6}/', implode(',', $row)))->toBe(0);
    }
});

it('reports the same figures as the page services (parity), keeps components without coverage as statuses, and has no PII', function () {
    [$usd, $alice] = exportFixture();
    $csv = exportCsv(userWithRole(Role::Finance));

    $finance = app(FinanceQuery::class);
    [$from, $to] = [CarbonImmutable::parse('2026-09-09', 'UTC'), CarbonImmutable::parse('2026-09-13', 'UTC')];
    $totals = $finance->totals($finance->build($from, $to));
    $current = app(MrrCalculator::class)->current()->byCurrency();

    $totalsRow = csvSection($csv, 'cost_totals')[0];
    $currentRow = csvSection($csv, 'current_run_rate')[0];
    $plans = csvSection($csv, 'by_plan');
    $top = csvSection($csv, 'top_subscribers');
    $history = csvSection($csv, 'mrr_snapshot_history');

    expect($totalsRow[4])->toBe($totals->knownProviderCost)->toBe('0.650000')
        ->and($totalsRow[5])->toBe('INCOMPLETE') // communication: no producer + WhatsApp usage — never "0.000000"
        ->and($totalsRow[6])->toBe('NO PRODUCER')
        ->and($totalsRow[3])->toBe((string) $totals->unpricedRows)->toBe('1')
        ->and($currentRow[1])->toBe('USD')
        ->and($currentRow[2])->toBe($current['USD']['mrr'])->toBe('10.000000')
        ->and($currentRow[4])->toBe($current['USD']['arpu'])
        ->and($currentRow[7])->toBe('1') // past_due_status_count
        ->and(collect($plans)->firstWhere(1, 'system')[7])->toBe('0.400000') // system bucket apart (known_cost column)
        ->and(collect($plans)->firstWhere(3, 'usd-monthly')[7])->toBe('0.250000')
        ->and($top)->toHaveCount(1)
        ->and($top[0][1])->toBe((string) $alice->id)
        ->and(collect($history)->firstWhere(1, '2026-09-09')[2])->toBe('NOT AVAILABLE')
        ->and(collect($history)->firstWhere(1, '2026-09-10')[2])->toBe('NOT AVAILABLE')
        ->and(collect($history)->firstWhere(1, '2026-09-11')[4])->toBe('10.000000')
        ->and(collect($history)->firstWhere(1, '2026-09-12')[2])->toBe('NOT CAPTURED')
        ->and($csv)->not->toContain($alice->email)->not->toContain($alice->name)->not->toContain('XXX');
});

it('shows ARPU as N/A in the CSV when nothing is active', function () {
    billingSubscriber(billingPlan(attrs: ['price' => '10.00', 'currency' => 'USD']), ['status' => SubscriptionStatus::PastDue]);

    $row = csvSection(exportCsv(userWithRole(Role::Finance)), 'current_run_rate')[0];

    expect($row[2])->toBe('0.000000')->and($row[4])->toBe('N/A')->and($row[5])->toBe('0')->and($row[7])->toBe('1');
});
