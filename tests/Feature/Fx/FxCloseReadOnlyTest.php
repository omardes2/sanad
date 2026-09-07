<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\Fx;
use App\Livewire\Dashboard\Finance\FxConversions;
use App\Livewire\Dashboard\Finance\FxConversionScopeDetail;
use App\Livewire\Dashboard\Finance\FxRates;
use App\Livewire\Dashboard\Finance\FxRateScopeDetail;
use App\Livewire\Dashboard\Finance\PeriodClose;
use App\Models\FxConversionScope;
use App\Models\FxRateScope;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Phase E5.2c — render = read-only, at the wire: the FX landing (pairs +
 * reporting currency + PREVIEW IMPACT), the rate list and scope detail, the
 * conversion list (LOAD SUBJECT) and scope detail, and the close page
 * (RUN PREFLIGHT, CHECK CURRENT DRIFT, every confirmation panel) issue NO
 * INSERT / UPDATE / DELETE / DDL on any table. Writes happen only after an
 * explicit action submit, through the E3 / E4 services.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('issues no write statement while rendering, filtering, paginating, previewing the currency impact, loading a subject, running preflight and checking drift', function () {
    $fx = closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    $rateScope = FxRateScope::query()->firstOrFail();
    $conversionScope = FxConversionScope::query()->firstOrFail();
    $finance = userWithRole(Role::Finance);
    $super = userWithRole(Role::SuperAdmin);
    $this->actingAs($finance);

    $writes = [];
    DB::listen(function (QueryExecuted $q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $q->sql) === 1) {
            $writes[] = $q->sql;
        }
    });

    $this->get(route('dashboard.finance.fx'))->assertOk();
    Livewire::actingAs($finance)->test(Fx::class)->set('rcCode', 'ILS')->call('previewImpact')->call('openConfirm', 'currency')->call('openConfirm', 'pair')->call('closeConfirm')->assertOk();

    $this->get(route('dashboard.finance.fx.rates', ['pair' => 'ILS:USD', 'from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk();
    Livewire::actingAs($finance)->test(FxRates::class, ['from' => '2026-08-01', 'to' => '2026-08-31'])->set('pair', 'ILS:USD')->call('gotoPage', 1)->call('openConfirm')->call('closeConfirm')->assertOk();
    $this->get(route('dashboard.finance.fx.rates.show', $rateScope->id))->assertOk();
    Livewire::actingAs($finance)->test(FxRateScopeDetail::class, ['scope' => $rateScope])->call('openConfirm')->call('closeConfirm')->assertOk();

    $this->get(route('dashboard.finance.fx.conversions', ['type' => 'customer_payment']))->assertOk();
    Livewire::actingAs($finance)->test(FxConversions::class)->set('type', 'customer_payment')->set('target', 'USD')->call('gotoPage', 1)
        ->set('convSubjectId', (string) $fx['ils']->id)->set('convTarget', 'USD')->call('loadSubject')->call('openConfirm')->call('closeConfirm')->assertOk();
    $this->get(route('dashboard.finance.fx.conversions.show', $conversionScope->id))->assertOk();
    Livewire::actingAs($finance)->test(FxConversionScopeDetail::class, ['scope' => $conversionScope])->call('openConfirm')->call('closeConfirm')->assertOk();

    $this->actingAs($super);
    $this->get(route('dashboard.finance.close', ['month' => '2026-08']))->assertOk();
    Livewire::actingAs($super)->test(PeriodClose::class)->set('month', '2026-08')->call('runPreflight')->call('checkDrift', $close->id)
        ->call('openConfirm', 'close')->call('openConfirm', 'reopen')->call('selectReopen', $close->id)->call('closeConfirm')->call('clearPreview')->assertOk();

    expect($writes)->toBe([]);
});

it('source level: the E5.2c pages call no model write and no query-builder write; every write goes through the E3 / E4 services', function () {
    $pages = [
        'Livewire/Dashboard/Finance/Fx.php', 'Livewire/Dashboard/Finance/FxRates.php', 'Livewire/Dashboard/Finance/FxRateScopeDetail.php',
        'Livewire/Dashboard/Finance/FxConversions.php', 'Livewire/Dashboard/Finance/FxConversionScopeDetail.php', 'Livewire/Dashboard/Finance/PeriodClose.php',
        'Livewire/Dashboard/Finance/Concerns/HandlesFxActions.php', 'Livewire/Dashboard/Finance/Concerns/HandlesCloseActions.php',
    ];

    foreach ($pages as $file) {
        $src = php_strip_whitespace(app_path($file));
        expect(preg_match('/->(update|delete|save|insert|forceFill|upsert|increment|decrement|touch|truncate)\(/', $src))->toBe(0, $file)
            ->and(preg_match('/DB::(statement|unprepared|insert|update|delete|table|transaction)/', $src))->toBe(0, $file)
            // the only `create(` a page may carry is the FX pair service call itself
            ->and(preg_match_all('/->create\(/', $src))->toBe(preg_match_all('/\$book->create\(/', $src), $file);
    }

    $expected = [
        'Livewire/Dashboard/Finance/Fx.php' => ['/\$book->create\(/' => 1, '/\$service->change\(/' => 1],
        'Livewire/Dashboard/Finance/FxRates.php' => ['/\$book->record\(/' => 1],
        'Livewire/Dashboard/Finance/FxRateScopeDetail.php' => ['/\$book->record\(/' => 1],
        'Livewire/Dashboard/Finance/FxConversions.php' => ['/\$service->convert\(/' => 1],
        'Livewire/Dashboard/Finance/FxConversionScopeDetail.php' => ['/\$service->convert\(/' => 1],
        'Livewire/Dashboard/Finance/PeriodClose.php' => ['/\$service->close\(/' => 1, '/\$service->reopen\(/' => 1],
    ];

    foreach ($expected as $file => $calls) {
        $src = php_strip_whitespace(app_path($file));
        foreach ($calls as $pattern => $count) {
            expect(preg_match_all($pattern, $src))->toBe($count, $file.' '.$pattern);
        }
    }

    // The close page never evaluates a month on render: preflight is reached from the on-demand action only.
    $close = php_strip_whitespace(app_path('Livewire/Dashboard/Finance/PeriodClose.php'));
    expect(preg_match_all('/->evaluate\(/', $close))->toBe(1)
        ->and(preg_match('/function runPreflight\(ClosePreflight \$preflight\)/', $close))->toBe(1)
        ->and(preg_match('/function render\([^)]*ClosePreflight/', $close))->toBe(0);
});
