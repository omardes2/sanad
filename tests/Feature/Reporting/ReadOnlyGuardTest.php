<?php

declare(strict_types=1);

use App\Livewire\Dashboard\AuditLogs;
use App\Livewire\Dashboard\Finance\CloseDetail;
use App\Livewire\Dashboard\Finance\PeriodClose;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Phase E5.1 is read-only, literally: rendering the finance overview, the
 * close history page, the close detail (including CHECK CURRENT DRIFT), every
 * CSV export and the audit page with subject filters issues NO INSERT,
 * UPDATE or DELETE on ANY table — proven at the wire level.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

/** Every write statement issued while $fn runs (any table). */
function writesDuring(callable $fn): array
{
    $writes = [];
    $listener = function (QueryExecuted $q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $q->sql) === 1) {
            $writes[] = $q->sql;
        }
    };
    DB::listen($listener);
    $fn();

    return $writes;
}

it('issues no INSERT / UPDATE / DELETE on any table while rendering the overview, the close pages, the drift check, the four exports and the audit subject filter', function () {
    $fx = closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    e1Payment($fx['subscriber'], ['amount' => '50.00', 'currency' => 'EUR', 'receivedAt' => CarbonImmutable::parse('2026-08-21', 'UTC')]);
    $finance = userWithRole(Role::Finance);
    $this->actingAs($finance);

    $writes = writesDuring(function () use ($finance, $close): void {
        $this->get(route('dashboard.finance', ['from' => '2026-07-15', 'to' => '2026-09-06']))->assertOk();
        $this->get(route('dashboard.finance.close', ['month' => '2026-08']))->assertOk();
        $this->get(route('dashboard.finance.close.show', $close->id))->assertOk();
        Livewire::actingAs($finance)->test(CloseDetail::class, ['close' => $close])->call('checkDrift')->assertSee('DRIFT SINCE CLOSE'); // the EUR payment landed after the close
        Livewire::actingAs($finance)->test(PeriodClose::class)->set('month', '2026-08')->call('checkDrift', $close->id)->assertSee('DRIFT SINCE CLOSE');
        $this->get(route('dashboard.finance.cash.export', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk()->streamedContent();
        $this->get(route('dashboard.finance.cost.export', ['from' => '2026-08', 'to' => '2026-08']))->assertOk()->streamedContent();
        $this->get(route('dashboard.finance.fx.export', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk()->streamedContent();
        $this->get(route('dashboard.finance.close.export', $close->id))->assertOk()->streamedContent();
        $this->get(route('dashboard.finance.export', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk()->streamedContent();
        $this->get(route('dashboard.audit', ['subject_type' => 'FinancePeriodCloseScope', 'subject_id' => $close->scope_id]))->assertOk()->assertSee('finance.period_closed');
        Livewire::actingAs($finance)->test(AuditLogs::class)->set('subject_type', 'CustomerPayment')->set('subject_id', '1')->assertOk();
    });

    expect($writes)->toBe([]);
});

it('source level: the reporting services and exporters contain no create / update / delete / save / insert call', function () {
    foreach (['Services/Reporting/FrozenCloseReader.php', 'Services/Reporting/ReconciledMonthSeries.php', 'Services/Reporting/CashExporter.php', 'Services/Reporting/CostExporter.php', 'Services/Reporting/FxExporter.php', 'Services/Reporting/CloseExporter.php', 'Services/Reporting/CsvWriter.php', 'Livewire/Dashboard/Finance/CloseDetail.php', 'Http/Controllers/Dashboard/FinanceReportExportController.php'] as $file) {
        $src = php_strip_whitespace(app_path($file));
        expect(preg_match('/->(create|update|delete|save|insert|forceFill|upsert|increment|decrement|touch|truncate)\(/', $src))->toBe(0, $file)
            ->and(preg_match('/DB::(statement|unprepared|insert|update|delete|table\([^)]*\)->(insert|update|delete))/', $src))->toBe(0, $file);
    }
});
