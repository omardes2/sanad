<?php

declare(strict_types=1);

use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Fx\ReportingView;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E3 — the reporting view reads conversions ONLY through the scope
 * projection (fx_conversion_scopes.current_conversion_id): never latest
 * created_at, never max(id), never first(), never a free query on
 * fx_conversions. A rate correction alone changes nothing; an explicit
 * conversion revision moves the pointer; the old revision stays, immutable,
 * out of the current total.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
    config(['billing.cost_currency' => 'USD']);
});

function currentCash(): array
{
    return app(ReportingView::class)->cash(CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'));
}

it('shows only the CURRENT conversion revision: v1 = 365 stays after the rate is corrected, v2 = 370 replaces it only when explicitly recorded, v1 remains as history', function () {
    $payment = e1Payment(billingSubscriber(), ['amount' => '100.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    app(ReportingCurrencyService::class)->change('ILS', 'ILS');
    $x = fxRate(['rate' => '3.65', 'rateDate' => '2026-08-10']);

    // 3) v1 with rate X ⇒ 365 ILS.
    $v1 = fxConvert('customer_payment', $payment->id, 'ILS', $x->id);
    $view = currentCash();
    expect($view['lines'][0]->targetAmount)->toBe('365.00')->and($view['lines'][0]->conversionId)->toBe($v1->id)->and($view['totals']['gross']->amount)->toBe('365.00');

    // 4–6) The rate is corrected: v1 is untouched, nothing is recomputed, the view still shows 365.
    $y = fxRate(['rate' => '3.70', 'rateDate' => '2026-08-10', 'expectedCurrentRateId' => $x->id]);
    $view = currentCash();
    expect(FxConversion::count())->toBe(1)->and($v1->fresh()->targetAmountAtScale())->toBe('365.00')->and($v1->fresh()->fx_rate_id)->toBe($x->id)
        ->and($view['lines'][0]->targetAmount)->toBe('365.00')->and($view['lines'][0]->fxRateId)->toBe($x->id)->and($view['totals']['gross']->amount)->toBe('365.00');

    // 7–8) An explicit correction v2 with the new rate ⇒ 370; the pointer moves.
    $v2 = fxConvert('customer_payment', $payment->id, 'ILS', $y->id, ['expectedCurrentConversionId' => $v1->id, 'reasonCode' => 'rate_corrected']);
    $scope = FxConversionScope::query()->firstOrFail();
    expect($scope->current_conversion_id)->toBe($v2->id)->and($scope->version)->toBe(2)->and($v2->supersedes_id)->toBe($v1->id);

    // 9–10) The view shows 370 only; v1 still exists, unchanged, and is not in the total (no double counting).
    $view = currentCash();
    expect($view['lines'])->toHaveCount(1)
        ->and($view['lines'][0]->targetAmount)->toBe('370.00')->and($view['lines'][0]->conversionId)->toBe($v2->id)->and($view['lines'][0]->fxRateId)->toBe($y->id)
        ->and($view['totals']['gross']->amount)->toBe('370.00')->and($view['totals']['gross']->converted)->toBe(1)
        ->and(FxConversion::count())->toBe(2)->and($v1->fresh()->targetAmountAtScale())->toBe('365.00')->and($v1->fresh()->supersedes_id)->toBeNull();
});

it('reads fx_conversions only by the ids taken from the scope projection — no ORDER BY, no LIMIT, no created_at, no MAX(id)', function () {
    $payment = e1Payment(billingSubscriber(), ['amount' => '100.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    app(ReportingCurrencyService::class)->change('ILS', 'ILS');
    $x = fxRate(['rate' => '3.65', 'rateDate' => '2026-08-10']);
    $v1 = fxConvert('customer_payment', $payment->id, 'ILS', $x->id);
    $v2 = fxConvert('customer_payment', $payment->id, 'ILS', $x->id, ['expectedCurrentConversionId' => $v1->id, 'reasonCode' => 'redo']);

    $conversionQueries = [];
    $scopeQueries = [];
    DB::listen(function (QueryExecuted $q) use (&$conversionQueries, &$scopeQueries): void {
        $sql = strtolower($q->sql);
        if (str_contains($sql, 'from "fx_conversions"')) {
            $conversionQueries[] = [$sql, $q->bindings];
        }
        if (str_contains($sql, 'from "fx_conversion_scopes"')) {
            $scopeQueries[] = $sql;
        }
    });

    $view = currentCash();

    expect($scopeQueries)->not->toBe([])->and($conversionQueries)->not->toBe([]);
    foreach ($conversionQueries as [$sql, $bindings]) {
        expect($sql)->toContain('where "id" in (')
            ->and($sql)->not->toContain('order by')->and($sql)->not->toContain('limit')->and($sql)->not->toContain('created_at')->and($sql)->not->toContain('max(')
            ->and($bindings)->toContain($v2->id)->and($bindings)->not->toContain($v1->id); // only the pointer's id is ever requested
    }
    expect($view['lines'][0]->conversionId)->toBe($v2->id);

    // Source level: the view never orders, limits or "firsts" fx_conversions.
    $src = php_strip_whitespace(app_path('Services/Fx/ReportingView.php'));
    expect(preg_match('/FxConversion::query\(\)->whereIn\(.id., \$current->values\(\)\)->get\(\)/', $src))->toBe(1)
        ->and(preg_match('/FxConversion::query\(\)[^;]*(orderBy|latest|oldest|first\(|max\(|limit\()/i', $src))->toBe(0)
        ->and(preg_match('/current_conversion_id/', $src))->toBe(1);
});
