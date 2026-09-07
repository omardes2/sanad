<?php

declare(strict_types=1);

use App\Models\FinancePeriodClose;
use App\Models\User;
use App\Services\Close\PeriodCloseService;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Fx\ReportingView;
use App\Services\Payments\CashCollectedQuery;
use App\Services\Reconciliation\CostReconciliationService;
use App\Services\Reconciliation\ReconciledCostQuery;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E5.1 — the four CSV exports share one contract (UTF-8 BOM, streamed,
 * no-store, nosniff, `section` column, meta rows first with timezone=UTC,
 * basis, reporting currency, window/month, generated_at; unknown = empty cell
 * + status; ids and bounded references only; no PII) and read the same
 * services as the pages. The close export reads frozen rows only.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function csvGet(User $user, string $route, array $params = []): TestResponse
{
    return test()->actingAs($user)->get(route($route, $params));
}

/** Parse a contract CSV into section => list<row as assoc array> (each section starts with its own header). */
function csvSections(string $csv): array
{
    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue('UTF-8 BOM missing');
    $csv = substr($csv, 3);
    $out = [];
    $headers = [];

    foreach (explode("\n", $csv) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line);
        if ($cells[0] === 'section') {
            $headers = array_slice($cells, 1);

            continue;
        }
        $out[$cells[0]][] = array_combine($headers, array_pad(array_slice($cells, 1), count($headers), ''));
    }

    return $out;
}

function assertContract(TestResponse $response, string $basis): array
{
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    $csv = $response->streamedContent();
    $sections = csvSections($csv);
    $meta = array_column($sections['meta'], 'value', 'key');

    expect(array_key_first($sections))->toBe('meta')
        ->and($meta['timezone'])->toBe('UTC')->and($meta['basis'])->toBe($basis)->and($meta)->toHaveKey('reporting_currency')->and($meta)->toHaveKey('generated_at')
        ->and(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $meta['generated_at']))->toBe(1)
        ->and(str_contains($csv, '@'))->toBeFalse() // no e-mail (bounded references never carry @)
        ->and(preg_match('/\+\d{9,}/', $csv))->toBe(0); // no E.164 phone

    return $sections;
}

it('exports are downloadable only with finance.export (four routes)', function () {
    rbacSync();
    closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    $routes = [
        ['dashboard.finance.cash.export', ['from' => '2026-08-01', 'to' => '2026-08-31']],
        ['dashboard.finance.cost.export', ['from' => '2026-08', 'to' => '2026-08']],
        ['dashboard.finance.fx.export', ['from' => '2026-08-01', 'to' => '2026-08-31']],
        ['dashboard.finance.close.export', ['close' => $close->id]],
    ];

    foreach ($routes as [$route, $params]) {
        $this->get(route($route, $params))->assertRedirect(route('login')); // guest, before any actingAs
    }

    foreach ($routes as [$route, $params]) {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route($route, $params))->assertForbidden();
        $this->actingAs(userWithRole(Role::Operations))->get(route($route, $params))->assertForbidden();
        $this->actingAs(userWithRole(Role::Support))->get(route($route, $params))->assertForbidden();
        $this->actingAs(userWithRole(Role::Finance))->get(route($route, $params))->assertOk();
        $this->actingAs(userWithRole(Role::SuperAdmin))->get(route($route, $params))->assertOk();
    }

    // finance.view alone is not enough for an export.
    $viewer = userWithRole(Role::Finance);
    Spatie\Permission\Models\Role::findByName(Role::Finance->value)->revokePermissionTo('finance.export');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($viewer->fresh())->get(route('dashboard.finance.close.export', $close->id))->assertForbidden();
});

it('validates windows: mandatory bounded dates for cash/fx, YYYY-MM for cost, 404 for an unknown close', function () {
    $user = userWithRole(Role::Finance);

    $this->actingAs($user)->get(route('dashboard.finance.cash.export'))->assertSessionHasErrors(['from', 'to']);
    $this->actingAs($user)->get(route('dashboard.finance.cash.export', ['from' => '2026-01-01', 'to' => '2027-06-30']))->assertStatus(422);
    $this->actingAs($user)->get(route('dashboard.finance.fx.export', ['from' => '2026-02-01', 'to' => '2026-01-01']))->assertStatus(422);
    $this->actingAs($user)->get(route('dashboard.finance.cost.export', ['from' => '2026-08-01', 'to' => '2026-08']))->assertSessionHasErrors(['from']);
    $this->actingAs($user)->get(route('dashboard.finance.cost.export', ['from' => '2025-01', 'to' => '2026-08']))->assertStatus(422);
    $this->actingAs($user)->get(route('dashboard.finance.close.export', 999))->assertNotFound();
});

it('cash export: same figures as CashCollectedQuery / ReportingView, FEES UNKNOWN as an empty cell with a status, NOT CONVERTED lines, reporting totals INCOMPLETE / NOT AVAILABLE, no PII', function () {
    $fx = closableMonth();
    $eur = e1Payment($fx['subscriber'], ['amount' => '50.00', 'currency' => 'EUR', 'receivedAt' => CarbonImmutable::parse('2026-08-21', 'UTC')]); // fee NULL, NOT CONVERTED
    [$from, $to] = [CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC')];

    $response = csvGet(userWithRole(Role::Finance), 'dashboard.finance.cash.export', ['from' => '2026-08-01', 'to' => '2026-08-31']);
    $sections = assertContract($response, 'LIVE / CURRENT');
    $csv = $response->streamedContent();
    $meta = array_column($sections['meta'], 'value', 'key');
    $summary = array_column($sections['cash_summary'], null, 'currency');
    $service = app(CashCollectedQuery::class)->summarise($from, $to);
    $view = app(ReportingView::class)->cash($from, $to);

    expect($meta['window_from'])->toBe('2026-08-01')->and($meta['window_to'])->toBe('2026-08-31')->and($meta['reporting_currency'])->toBe('USD')
        ->and(array_keys($sections))->toBe(['meta', 'cash_summary', 'reporting_totals', 'payments', 'refunds']) // the two allocation sections are present as headers but empty here
        ->and(substr_count($csv, 'section,allocation_id,payment_id,subscription_id'))->toBe(1)->and(substr_count($csv, 'section,refund_allocation_id,refund_id'))->toBe(1)
        ->and($summary['USD']['gross_cash_collected'])->toBe($service['USD']->grossCashCollected)->and($summary['USD']['net_cash_after_gateway_fees'])->toBe('87.00')->and($summary['USD']['fees_status'])->toBe('known')
        ->and($summary['EUR']['gateway_fees_known'])->toBe('0.00')->and($summary['EUR']['fees_unknown_count'])->toBe('1')->and($summary['EUR']['fees_status'])->toBe('FEES UNKNOWN')
        ->and($summary['EUR']['net_cash_after_gateway_fees'])->toBe('')->and($summary['EUR']['net_cash_after_gateway_fees_status'])->toBe('NOT AVAILABLE (FEES UNKNOWN)');

    $totals = array_column($sections['reporting_totals'], null, 'figure');
    expect($totals['Gross Cash Collected']['amount'])->toBe('')->and($totals['Gross Cash Collected']['status'])->toBe('INCOMPLETE / NOT AVAILABLE')->and($totals['Gross Cash Collected']['not_converted'])->toBe('1')
        ->and($view['totals']['gross']->amount)->toBeNull();

    $payments = array_column($sections['payments'], null, 'payment_id');
    expect($payments[(string) $eur->id]['gateway_fee_amount'])->toBe('')->and($payments[(string) $eur->id]['fee_status'])->toBe('FEES UNKNOWN')->and($payments[(string) $eur->id]['reporting_status'])->toBe('NOT CONVERTED')->and($payments[(string) $eur->id]['reporting_amount'])->toBe('')
        ->and($payments[(string) $fx['ils']->id]['reporting_status'])->toBe('CONVERTED')->and($payments[(string) $fx['ils']->id]['reporting_amount'])->toBe('100.00')->and($payments[(string) $fx['ils']->id]['fx_rate_id'])->toBe((string) $fx['rate']->id)->and($payments[(string) $fx['ils']->id]['fx_direction'])->toBe('inverse')->and($payments[(string) $fx['ils']->id]['fx_rate_date'])->toBe('2026-08-10')
        ->and($payments[(string) $fx['usd']->id]['reporting_status'])->toBe('NATIVE')->and($payments[(string) $fx['usd']->id]['received_at_utc'])->toBe('2026-08-10T09:00:00Z')->and($payments[(string) $fx['usd']->id]['subscriber_id'])->toBe((string) $fx['subscriber']->id)
        ->and($sections['refunds'][0]['amount'])->toBe('10.00')->and($sections['refunds'][0]['payment_id'])->toBe((string) $fx['usd']->id)
        ->and(array_keys($payments[(string) $fx['usd']->id]))->not->toContain('name')->not->toContain('email')->not->toContain('phone');
});

it('cost export: same rows as ReconciledCostQuery with UNKNOWN variance as empty + status, CONFIRMED ZERO as a status, flags, evidence rows and adjustments', function () {
    $fx = closableMonth();
    financeRow(['provider' => 'groq', 'provider_cost' => '1.000000', 'total_cost' => '1.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-20', 'UTC')]); // ledger moved

    $sections = assertContract(csvGet(userWithRole(Role::Finance), 'dashboard.finance.cost.export', ['from' => '2026-08', 'to' => '2026-08']), 'LIVE / CURRENT');
    $meta = array_column($sections['meta'], 'value', 'key');
    $scopes = array_column($sections['scopes'], null, 'reconciliation_id');
    $service = array_column(array_map(fn ($s) => (array) $s, app(ReconciledCostQuery::class)->summarise('2026-08', '2026-08')), null, 'reconciliationId');

    expect($meta['month_from'])->toBe('2026-08')->and($meta['month_to'])->toBe('2026-08')
        ->and(array_keys($sections))->toBe(['meta', 'scopes', 'reporting', 'reporting_totals', 'invoices', 'invoice_lines', 'evidence_allocations', 'adjustments'])
        ->and(count($scopes))->toBe(3)
        ->and($scopes[(string) $fx['reconciliation']->id]['base_reconciled_amount'])->toBe('60.000000')->and($scopes[(string) $fx['reconciliation']->id]['adjustments'])->toBe('-5.000000')->and($scopes[(string) $fx['reconciliation']->id]['adjusted_reconciled_cost'])->toBe('55.000000')
        ->and($scopes[(string) $fx['reconciliation']->id]['ledger_moved'])->toBe('true')->and($scopes[(string) $fx['reconciliation']->id]['flags'])->toContain('LEDGER MOVED SINCE RECONCILIATION')
        ->and($scopes[(string) $fx['reconciliation']->id]['variance_vs_known_calculated'])->toBe($service[$fx['reconciliation']->id]['varianceVsKnownCalculated'] ?? '')
        ->and($scopes[(string) $fx['communication']->id]['status'])->toBe('CONFIRMED ZERO')->and($scopes[(string) $fx['communication']->id]['variance_vs_known_calculated'])->toBe('')->and($scopes[(string) $fx['communication']->id]['variance_status'])->toContain('UNKNOWN')
        ->and(array_column($sections['invoices'], 'invoice_id'))->toBe([(string) $fx['invoice']->id])->and($sections['invoices'][0]['current_status'])->toBe('confirmed')->and($sections['invoices'][0]['period_start_utc'])->toBe('2026-08-01T00:00:00Z')
        ->and($sections['invoice_lines'][0]['amount'])->toBe('60.000000')->and($sections['evidence_allocations'][0]['reconciliation_id'])->toBe((string) $fx['reconciliation']->id)
        ->and($sections['adjustments'][0]['amount'])->toBe('-5.000000')->and($sections['adjustments'][0]['reason_code'])->toBe('credit_note')
        ->and(array_column($sections['reporting'], 'reporting_status'))->toBe(['NATIVE', 'NATIVE', 'NATIVE']);
});

it('fx export: pairs, quote revisions with is_current_revision, and frozen conversions with is_current_conversion — a later correction never rewrites a conversion', function () {
    $fx = closableMonth();
    $corrected = fxRate(['rate' => '3.70', 'rateDate' => '2026-08-10', 'expectedCurrentRateId' => $fx['rate']->id, 'reasonCode' => 'correction', 'evidenceRef' => 'boi:rev2']);

    $sections = assertContract(csvGet(userWithRole(Role::Finance), 'dashboard.finance.fx.export', ['from' => '2026-08-01', 'to' => '2026-08-31']), 'LIVE / CURRENT');
    $rates = array_column($sections['rates'], null, 'rate_id');
    $conversions = array_column($sections['conversions'], null, 'conversion_id');

    expect(array_keys($sections))->toBe(['meta', 'pairs', 'rates', 'conversions'])
        ->and($sections['pairs'][0]['pair_key'])->toBe('ILS:USD')
        ->and($rates[(string) $fx['rate']->id]['is_current_revision'])->toBe('false')->and($rates[(string) $corrected->id]['is_current_revision'])->toBe('true')->and($rates[(string) $corrected->id]['supersedes_id'])->toBe((string) $fx['rate']->id)
        ->and($conversions[(string) $fx['conversion']->id]['fx_rate_id'])->toBe((string) $fx['rate']->id)->and($conversions[(string) $fx['conversion']->id]['rate_snapshot'])->toBe('3.650000000000')->and($conversions[(string) $fx['conversion']->id]['target_amount'])->toBe('100.00')->and($conversions[(string) $fx['conversion']->id]['is_current_conversion'])->toBe('true')
        ->and($conversions[(string) $fx['conversion']->id]['subject_type'])->toBe('customer_payment')->and($conversions[(string) $fx['conversion']->id]['policy_date'])->toBe('2026-08-10');
});

it('close export: frozen rows only — figures, conditions, expected providers and every input row exactly as frozen, unchanged after live data, FX and reporting currency move on', function () {
    $fx = closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    $before = csvGet(userWithRole(Role::Finance), 'dashboard.finance.close.export', ['close' => $close->id])->streamedContent();

    app(CostReconciliationService::class)->adjust($fx['reconciliation']->id, '-1.000000', 'credit', 'cn:2', e2Key());
    fxRate(['rate' => '3.70', 'rateDate' => '2026-08-10', 'expectedCurrentRateId' => $fx['rate']->id, 'reasonCode' => 'correction', 'evidenceRef' => 'boi:rev2']);
    app(ReportingCurrencyService::class)->change('ILS', 'ILS');

    $response = csvGet(userWithRole(Role::Finance), 'dashboard.finance.close.export', ['close' => $close->id]);
    $sections = assertContract($response, 'FROZEN CLOSE REVISION 1');
    $after = $response->streamedContent();
    $meta = array_column($sections['meta'], 'value', 'key');
    $figures = array_column($sections['figures'], null, 'figure');
    $inputs = $sections['inputs'];

    // Byte-identical except generated_at.
    $strip = fn (string $csv) => preg_replace('/^meta,generated_at,.*$/m', '', $csv);
    expect($strip($after))->toBe($strip($before))
        ->and(array_keys($sections))->toBe(['meta', 'figures', 'conditions', 'expected_providers', 'inputs'])
        ->and($meta['close_id'])->toBe((string) $close->id)->and($meta['month'])->toBe('2026-08')->and($meta['reporting_currency'])->toBe('USD')->and($meta['revision'])->toBe('1')->and($meta['input_hash'])->toBe($close->input_hash)
        ->and($meta['period_start_utc'])->toBe('2026-08-01T00:00:00Z')->and($meta['period_end_utc'])->toBe('2026-09-01T00:00:00Z')->and($meta['is_current_close'])->toBe('true')
        ->and($figures['reconciled_cash_contribution']['amount'])->toBe('131.000000')->and($figures['reconciled_cash_contribution']['status'])->toBe('frozen')->and($figures['gross_cash_collected']['amount'])->toBe('200.000000')
        ->and(array_column($sections['expected_providers'], 'provider_key'))->toBe(['groq'])
        ->and(count($inputs))->toBe(9)
        ->and(array_column($sections['conditions'], 'code'))->toBe(['CONFIRMED_ZERO', 'CALCULATED_COVERAGE_PARTIAL', 'CONFIRMED_ZERO', 'CALCULATED_COVERAGE_PARTIAL']);

    $fee = collect($inputs)->first(fn ($r) => $r['input_type'] === 'gateway_fee' && $r['input_id'] === (string) $fx['ils']->id);
    expect($fee['amount'])->toBe('3.650000')->and($fee['currency'])->toBe('ILS')->and($fee['status'])->toBe('CONVERTED')->and($fee['reporting_amount'])->toBe('1.000000')->and($fee['reporting_currency'])->toBe('USD')
        ->and($fee['fx_rate_id'])->toBe((string) $fx['rate']->id)->and($fee['fx_rate_date'])->toBe('2026-08-10')->and($fee['fx_rate_snapshot'])->toBe('3.650000000000')->and($fee['fx_direction'])->toBe('inverse')->and($fee['flags'])->toContain('payment:'.$fx['ils']->id);

    // A NULL figure is an empty cell + NOT AVAILABLE — proven on a reopen record (no figures) and on the frozen conditions of a reopen export.
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $reopen = app(PeriodCloseService::class)->reopen($close->id, $close->id, 'restatement', 'memo:1', 'REOPEN 2026-08');
    $sections = assertContract(csvGet(userWithRole(Role::Finance), 'dashboard.finance.close.export', ['close' => $reopen->id]), 'REOPEN RECORD (revision 1)');
    $figures = array_column($sections['figures'], null, 'figure');
    expect($figures['reconciled_cash_contribution']['amount'])->toBe('')->and($figures['reconciled_cash_contribution']['status'])->toBe('NOT AVAILABLE')->and($sections['inputs'] ?? [])->toBe([])
        ->and(array_column($sections['meta'], 'value', 'key')['reopened_close_id'])->toBe((string) $close->id)
        ->and((string) FinancePeriodClose::query()->findOrFail($close->id)->reconciled_cash_contribution)->toBe('131.000000');
});

it('every export carries no free text and no raw payload: only the contract columns', function () {
    $fx = closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    $user = userWithRole(Role::Finance);

    $all = [
        csvGet($user, 'dashboard.finance.cash.export', ['from' => '2026-08-01', 'to' => '2026-08-31'])->streamedContent(),
        csvGet($user, 'dashboard.finance.cost.export', ['from' => '2026-08', 'to' => '2026-08'])->streamedContent(),
        csvGet($user, 'dashboard.finance.fx.export', ['from' => '2026-08-01', 'to' => '2026-08-31'])->streamedContent(),
        csvGet($user, 'dashboard.finance.close.export', ['close' => $close->id])->streamedContent(),
    ];

    foreach ($all as $csv) {
        $headers = [];
        foreach (explode("\n", substr($csv, 3)) as $line) {
            if (str_starts_with($line, 'section,')) {
                $headers = [...$headers, ...str_getcsv($line)];
            }
        }
        expect($headers)->not->toContain('name')->not->toContain('email')->not->toContain('phone')->not->toContain('note')->not->toContain('notes')->not->toContain('payload')->not->toContain('metadata')->not->toContain('external_note')
            ->and($csv)->not->toContain($fx['subscriber']->email)->not->toContain($fx['subscriber']->name)->not->toContain('{"');
    }
});
