<?php

declare(strict_types=1);

use App\Models\FxConversionScope;
use App\Models\FxRateScope;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E5.2c performance guards: a fixed number of queries per FX page
 * whatever the number of rows (grouped counts and one lookup per page — never
 * one query per pair, per scope or per revision), and on PostgreSQL the list
 * filters use the indexes that already exist.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function e3Queries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

/** One quote per day for one pair, straight into the tables (no service, no audit): planner- and N+1-realistic rows. */
function seedQuotes(string $base, string $quote, int $days, string $from = '2026-01-01'): void
{
    $pair = fxPair($base, $quote);
    $start = CarbonImmutable::parse($from, 'UTC');

    for ($i = 0; $i < $days; $i++) {
        $date = $start->addDays($i)->format('Y-m-d');
        $scopeId = DB::table('fx_rate_scopes')->insertGetId(['fx_pair_id' => $pair->id, 'rate_date' => $date, 'current_rate_id' => null, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $rateId = DB::table('fx_rates')->insertGetId([
            'fx_pair_id' => $pair->id, 'scope_id' => $scopeId, 'rate_date' => $date, 'base_currency' => $pair->base_currency, 'quote_currency' => $pair->quote_currency,
            'rate' => '3.650000000000', 'source' => 'manual', 'evidence_ref' => 'seed:'.$date, 'recorded_by_ref' => 'seed', 'created_at' => now(),
        ]);
        DB::table('fx_rate_scopes')->where('id', $scopeId)->update(['current_rate_id' => $rateId]);
    }
}

it('FX landing: the same number of queries with 1 pair and with 25 pairs (grouped scope and revision counts, never one query per pair)', function () {
    $this->actingAs(userWithRole(Role::Finance));
    fxRate(); // USD/ILS with one quote
    $url = route('dashboard.finance.fx');
    $this->get($url)->assertOk();
    $small = e3Queries(fn () => $this->get($url)->assertOk());

    for ($i = 0; $i < 24; $i++) {
        seedQuotes('A'.chr(65 + intdiv($i, 24)).chr(65 + $i % 24), 'Z'.chr(65 + intdiv($i, 24)).chr(65 + $i % 24), 3);
    }
    $large = e3Queries(fn () => $this->get($url)->assertOk());

    expect($large)->toBe($small);
});

it('rate list and conversion list: the same number of queries with 3 rows and with 40 rows (paginated, current revisions and counts keyed per page)', function () {
    $fx = closableMonth();
    $this->actingAs(userWithRole(Role::Finance));
    $rates = route('dashboard.finance.fx.rates', ['from' => '2026-01-01', 'to' => '2026-09-06']);
    $conversions = route('dashboard.finance.fx.conversions');
    $this->get($rates)->assertOk();
    $this->get($conversions)->assertOk();
    $smallRates = e3Queries(fn () => $this->get($rates)->assertOk());
    $smallConversions = e3Queries(fn () => $this->get($conversions)->assertOk());

    seedQuotes('QAA', 'QBB', 40, '2026-02-01');
    for ($i = 0; $i < 40; $i++) {
        DB::table('fx_conversion_scopes')->insert(['subject_type' => 'customer_payment', 'subject_id' => 900 + $i, 'purpose' => 'reporting', 'target_currency' => 'USD', 'current_conversion_id' => null, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    $largeRates = e3Queries(fn () => $this->get($rates)->assertOk());
    $largeConversions = e3Queries(fn () => $this->get($conversions)->assertOk());

    expect($largeRates)->toBe($smallRates)->and($largeConversions)->toBe($smallConversions);
});

it('rate scope detail and conversion scope detail: the same number of queries with 1 revision and with 12 revisions (history and frozen conversions in one query each)', function () {
    $fx = closableMonth();
    $this->actingAs(userWithRole(Role::Finance));
    $rateScope = FxRateScope::query()->firstOrFail();
    $conversionScope = FxConversionScope::query()->firstOrFail();
    $rateUrl = route('dashboard.finance.fx.rates.show', $rateScope->id);
    $conversionUrl = route('dashboard.finance.fx.conversions.show', $conversionScope->id);
    $this->get($rateUrl)->assertOk();
    $this->get($conversionUrl)->assertOk();
    $smallRate = e3Queries(fn () => $this->get($rateUrl)->assertOk());
    $smallConversion = e3Queries(fn () => $this->get($conversionUrl)->assertOk());

    $rate = $fx['rate'];
    for ($i = 0; $i < 12; $i++) {
        $rate = fxRate(['rate' => '3.'.(600 + $i), 'rateDate' => '2026-08-10', 'expectedCurrentRateId' => $rate->id, 'evidenceRef' => 'fix:'.$i]);
        fxConvert('customer_payment', $fx['ils']->id, 'USD', $rate->id, ['expectedCurrentConversionId' => FxConversionScope::query()->whereKey($conversionScope->id)->value('current_conversion_id')]);
    }

    $largeRate = e3Queries(fn () => $this->get($rateUrl)->assertOk());
    $largeConversion = e3Queries(fn () => $this->get($conversionUrl)->assertOk());

    expect($largeRate)->toBe($smallRate)->and($largeConversion)->toBe($smallConversion);
});

/** Bulk rows straight into the tables at planner-realistic volume: 6,000 quote scopes (5 pairs × chronological days) and 6,000 conversion scopes. */
function seedFxVolume(): void
{
    $pairs = [];
    for ($p = 0; $p < 5; $p++) {
        [$base, $quote] = ['P'.chr(65 + $p).'A', 'Q'.chr(65 + $p).'B'];
        $pairs[] = DB::table('fx_pairs')->insertGetId(['pair_key' => min($base, $quote).':'.max($base, $quote), 'base_currency' => $base, 'quote_currency' => $quote, 'created_by_ref' => 'seed', 'created_at' => now(), 'updated_at' => now()]);
    }

    $start = CarbonImmutable::parse('2023-01-01', 'UTC');
    $rows = [];
    for ($i = 0; $i < 6000; $i++) {
        // Chronological: ids grow with the dates, the way quotes are actually recorded.
        $rows[] = ['fx_pair_id' => $pairs[$i % 5], 'rate_date' => $start->addDays(intdiv($i, 5))->format('Y-m-d'), 'current_rate_id' => null, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        if (count($rows) === 500) {
            DB::table('fx_rate_scopes')->insert($rows);
            $rows = [];
        }
    }

    $rows = [];
    for ($i = 0; $i < 6000; $i++) {
        $rows[] = ['subject_type' => ['customer_payment', 'customer_refund', 'cost_reconciliation', 'cost_adjustment'][$i % 4], 'subject_id' => 100000 + $i, 'purpose' => 'reporting', 'target_currency' => ['USD', 'ILS', 'EUR'][$i % 3], 'current_conversion_id' => null, 'version' => 0, 'created_at' => now(), 'updated_at' => now()];
        if (count($rows) === 500) {
            DB::table('fx_conversion_scopes')->insert($rows);
            $rows = [];
        }
    }

    // One current revision per scope, in one statement (this seeder runs on PostgreSQL only).
    DB::statement("INSERT INTO fx_rates (fx_pair_id, scope_id, rate_date, base_currency, quote_currency, rate, source, evidence_ref, recorded_by_ref, created_at)
        SELECT s.fx_pair_id, s.id, s.rate_date, p.base_currency, p.quote_currency, 3.65, 'manual', 'seed', 'seed', now() FROM fx_rate_scopes s JOIN fx_pairs p ON p.id = s.fx_pair_id");
    DB::statement('UPDATE fx_rate_scopes s SET current_rate_id = r.id FROM fx_rates r WHERE r.scope_id = s.id');

    DB::statement('ANALYZE fx_pairs');
    DB::statement('ANALYZE fx_rate_scopes');
    DB::statement('ANALYZE fx_conversion_scopes');
    DB::statement('ANALYZE fx_rates');
}

it('PostgreSQL EXPLAIN at 6,000 rows: the pair-filtered quote window uses fx_rate_scopes_pair_date_unique, the conversion list and the revision history stay on their indexes, and the window-only quote list is bounded by the page (measured, no index migration)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('EXPLAIN check runs on PostgreSQL only.');
    }

    seedFxVolume();
    $pairId = DB::table('fx_pairs')->orderByDesc('id')->value('id');
    $plan = fn (string $sql, array $b) => collect(DB::select('EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF) '.$sql, $b))->pluck('QUERY PLAN')->implode("\n");
    $removed = static fn (string $p): int => preg_match('/Rows Removed by Filter: (\d+)/', $p, $m) === 1 ? (int) $m[1] : 0;

    // 1) The pair-filtered list — the way the page is reached from a pair — is served end to end by the existing
    //    unique index (fx_pair_id, rate_date): the page's own order IS the index order, so it reads a page of rows.
    $paired = $plan('SELECT * FROM fx_rate_scopes WHERE fx_pair_id = ? AND rate_date >= ? AND rate_date <= ? ORDER BY rate_date DESC, id DESC LIMIT 25', [$pairId, '2023-06-01', '2023-09-01']);
    expect($paired)->toContain('fx_rate_scopes_pair_date_unique')->not->toContain('Seq Scan on fx_rate_scopes')
        ->and($removed($paired))->toBeLessThan(100);

    // 2) The conversion list uses the primary key backwards and discards only the other type/target combinations — bounded, not the table.
    $conversions = $plan('SELECT * FROM fx_conversion_scopes WHERE purpose = ? AND subject_type = ? AND target_currency = ? ORDER BY id DESC LIMIT 25', ['reporting', 'customer_payment', 'USD']);
    expect($conversions)->toContain('fx_conversion_scopes_pkey')->not->toContain('Seq Scan on fx_conversion_scopes')
        ->and($removed($conversions))->toBeLessThan(1000);

    // 3) The revision history of one scope is an index scan on fx_rates_scope_idx, whatever the number of quotes.
    $scopeId = DB::table('fx_rate_scopes')->where('fx_pair_id', $pairId)->orderByDesc('id')->value('id');
    expect($plan('SELECT * FROM fx_rates WHERE scope_id = ? ORDER BY id DESC', [$scopeId]))->toContain('fx_rates_scope_idx');

    // 4) The window-only quote list (no pair chosen) has no index for a global rate_date order: it is a scan of the
    //    table with a top-N sort of the window, and its cost is the SAME wherever the window sits (no walking back
    //    over newer ids). Measured here as a recorded fact — closing it would need an index on
    //    fx_rate_scopes (rate_date, id), which is NOT added without approval.
    $recent = $plan('SELECT * FROM fx_rate_scopes WHERE rate_date >= ? AND rate_date <= ? ORDER BY rate_date DESC, id DESC LIMIT 25', ['2026-01-01', '2026-04-01']);
    $old = $plan('SELECT * FROM fx_rate_scopes WHERE rate_date >= ? AND rate_date <= ? ORDER BY rate_date DESC, id DESC LIMIT 25', ['2023-01-01', '2023-04-01']);

    expect($recent)->toContain('top-N heapsort')->and($old)->toContain('top-N heapsort')
        ->and($removed($old))->toBe($removed($recent)); // identical work for an old and a recent window
});
