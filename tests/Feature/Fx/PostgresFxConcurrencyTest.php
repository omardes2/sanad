<?php

declare(strict_types=1);

use App\Enums\FxSubjectType;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\CostInvoice;
use App\Models\CostInvoiceAllocation;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Models\FxPair;
use App\Models\FxRate;
use App\Models\FxRateScope;
use App\Models\User;
use App\Services\Fx\ReportingConversionService;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for Phase E3 on PostgreSQL (separate PHP processes):
 *  - pair reverse race: 6 creators of USD/ILS and ILS/USD at once ⇒ exactly
 *    one canonical pair;
 *  - rate revision race: 6 corrections from the same expected revision ⇒ one
 *    new revision, five stale, one pointer move, one audit;
 *  - reporting conversion race: 6 conversions of one subject from the same
 *    expectation ⇒ one conversion, five stale;
 *  - cross-currency reconciliation while the quote is corrected: the
 *    reconciliation that named rate X freezes X; a concurrent revision does
 *    not change it; naming X after it was superseded is stale.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Real concurrency test requires the pgsql connection.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('PostgreSQL is not reachable.');
    }
});

it('of 6 concurrent creations of USD/ILS and ILS/USD exactly one canonical pair exists', function () {
    [$a, $b] = fxCodes();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = fxRun($i % 2 === 0 ? ['create-pair', $a, $b] : ['create-pair', $b, $a]);
        }
        $outcomes = fxOutcomes($processes);
        $pairs = FxPair::query()->where('pair_key', FxPair::keyFor($a, $b))->get();

        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'rejected:pair_exists'))->toHaveCount(5)
            ->and($pairs)->toHaveCount(1)
            ->and(FxPair::query()->where(fn ($q) => $q->whereIn('base_currency', [$a, $b])->orWhereIn('quote_currency', [$a, $b]))->count())->toBe(1)
            ->and(AuditLog::where('action', 'fx.pair_created')->where('subject_id', $pairs[0]->id)->count())->toBe(1);
    } finally {
        fxCleanup([$a, $b]);
    }
});

it('of 6 concurrent corrections of one (pair, date) quote from the same expected revision exactly one wins: one new revision, five stale, one pointer move, one audit', function () {
    [$a, $b] = fxCodes();
    $first = fxRate(['baseCurrency' => $a, 'quoteCurrency' => $b, 'rate' => '3.65', 'rateDate' => '2026-08-10']);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = fxRun(['record-rate', $a, $b, '2026-08-10', '3.7'.$i, (string) $first->id]);
        }
        $outcomes = fxOutcomes($processes);
        $scope = FxRateScope::query()->whereKey($first->scope_id)->firstOrFail();

        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and(FxRate::query()->where('scope_id', $scope->id)->count())->toBe(2)
            ->and('ok:'.$scope->current_rate_id)->toBe(array_values(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))[0])
            ->and($scope->version)->toBe(2)
            ->and(FxRate::query()->find($scope->current_rate_id)->supersedes_id)->toBe($first->id)
            ->and(AuditLog::where('action', 'fx.rate_recorded')->where('subject_id', $scope->id)->count())->toBe(2); // first + the winner
    } finally {
        fxCleanup([$a, $b]);
    }
});

it('of 6 concurrent reporting conversions of one subject from the same expectation exactly one is written; five are stale', function () {
    [$a, $b] = fxCodes();
    $user = User::factory()->create(['is_admin' => false]);
    $payment = e1Payment($user, ['amount' => '100.00', 'currency' => $a, 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $rate = fxRate(['baseCurrency' => $a, 'quoteCurrency' => $b, 'rate' => '2', 'rateDate' => '2026-08-10']);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = fxRun(['convert', 'customer_payment', (string) $payment->id, $b, (string) $rate->id, 'none']);
        }
        $outcomes = fxOutcomes($processes);
        $scope = FxConversionScope::query()->where('subject_type', 'customer_payment')->where('subject_id', $payment->id)->where('target_currency', $b)->firstOrFail();

        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and(FxConversion::query()->where('scope_id', $scope->id)->count())->toBe(1)
            ->and(FxConversion::query()->find($scope->current_conversion_id)->targetAmountAtScale())->toBe('200.00')
            ->and(AuditLog::where('action', 'fx.converted')->where('subject_id', $payment->id)->count())->toBe(1);
    } finally {
        fxCleanup([$a, $b], $user);
    }
});

it('of 6 concurrent conversion CORRECTIONS from the same current revision exactly one wins; five are stale; one pointer; the reporting total counts the current revision once', function () {
    [$a, $b] = fxCodes();
    $user = User::factory()->create(['is_admin' => false]);
    $payment = e1Payment($user, ['amount' => '100.00', 'currency' => $a, 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $x = fxRate(['baseCurrency' => $a, 'quoteCurrency' => $b, 'rate' => '3.65', 'rateDate' => '2026-08-10']);
    $v1 = fxConvert('customer_payment', $payment->id, $b, $x->id);
    $y = fxRate(['baseCurrency' => $a, 'quoteCurrency' => $b, 'rate' => '3.70', 'rateDate' => '2026-08-10', 'expectedCurrentRateId' => $x->id]);

    try {
        // The rate correction alone changed nothing.
        expect(FxConversion::query()->where('scope_id', $v1->scope_id)->count())->toBe(1)->and($v1->fresh()->targetAmountAtScale())->toBe('365.00');

        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = fxRun(['convert', 'customer_payment', (string) $payment->id, $b, (string) $y->id, (string) $v1->id]);
        }
        $outcomes = fxOutcomes($processes);
        $scope = FxConversionScope::query()->whereKey($v1->scope_id)->firstOrFail();
        $winner = array_values(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')));

        expect($winner)->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and(FxConversion::query()->where('scope_id', $scope->id)->count())->toBe(2) // v1 + the one winner
            ->and('ok:'.$scope->current_conversion_id)->toBe($winner[0])
            ->and($scope->version)->toBe(2)
            ->and(FxConversion::query()->find($scope->current_conversion_id)->targetAmountAtScale())->toBe('370.00')
            ->and($v1->fresh()->targetAmountAtScale())->toBe('365.00'); // history intact

        // One scope, one pointer: the projection the reporting view reads can only yield the current revision once.
        expect(FxConversionScope::query()->where('subject_type', 'customer_payment')->where('subject_id', $payment->id)->count())->toBe(1)
            ->and(ReportingConversionService::currentFor(FxSubjectType::CustomerPayment, $payment->id, $b)?->targetAmountAtScale())->toBe('370.00');
    } finally {
        fxCleanup([$a, $b], $user);
    }
});

it('freezes the exact rate a cross-currency reconciliation named even while the quote is corrected concurrently; naming the superseded rate afterwards is stale', function () {
    [$a, $b] = fxCodes();
    $cp = 'fxrace-'.strtolower(str()->random(6));
    AiProvider::factory()->create(['key' => $cp, 'driver' => 'groq', 'priority' => 1]);
    $invoice = e2ConfirmedInvoice(['service' => '600.000000'], ['counterpartyKey' => $cp, 'currency' => $a, 'issuedAt' => CarbonImmutable::parse('2026-09-02', 'UTC')]);
    $line = $invoice->lines()->firstOrFail();
    $x = fxRate(['baseCurrency' => $a, 'quoteCurrency' => $b, 'rate' => '2', 'rateDate' => '2026-09-02']);

    try {
        // Six reconciliations (six months) naming X, interleaved with three corrections of X — all started at once.
        $processes = [];
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $i => $month) {
            $reconcile = new Process(['php', 'artisan', 'sanad:reconciliation-probe', 'reconcile', 'provider', $cp, $month, $b, 'none', $line->id.':10.000000:'.$x->id], base_path());
            $reconcile->start();
            $processes[] = $reconcile;

            if ($i < 3) {
                $processes[] = fxRun(['record-rate', $a, $b, '2026-09-02', '3', (string) $x->id]); // fxRun() starts it
            }
        }
        $outcomes = fxOutcomes($processes);
        $recs = CostReconciliation::query()->where('counterparty_key', $cp)->get();
        $rows = CostInvoiceAllocation::query()->whereIn('cost_reconciliation_id', $recs->pluck('id'))->get();

        // Every reconciliation that succeeded used X (rate 2 ⇒ 20.000000) — never the concurrent correction (rate 3).
        expect($rows->pluck('fx_rate_id')->unique()->all())->toBe($recs->isEmpty() ? [] : [$x->id])
            ->and($rows->pluck('amount')->map(fn ($v) => (string) $v)->unique()->all())->toBe($recs->isEmpty() ? [] : ['20.000000'])
            ->and(count(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:'))))->toBeGreaterThanOrEqual(1) // at least the rate winner
            ->and(FxRate::query()->where('scope_id', $x->scope_id)->count())->toBe(2); // exactly one correction won

        // X is superseded now: naming it again is stale; naming the current revision works.
        $current = FxRateScope::query()->whereKey($x->scope_id)->value('current_rate_id');
        $stale = new Process(['php', 'artisan', 'sanad:reconciliation-probe', 'reconcile', 'provider', $cp, '2026-07', $b, 'none', $line->id.':10.000000:'.$x->id], base_path());
        $stale->run();
        $fresh = new Process(['php', 'artisan', 'sanad:reconciliation-probe', 'reconcile', 'provider', $cp, '2026-08', $b, 'none', $line->id.':10.000000:'.$current], base_path());
        $fresh->run();
        expect(trim($stale->getOutput()))->toBe('stale')->and(trim($fresh->getOutput()))->toStartWith('ok:');
    } finally {
        $recIds = CostReconciliation::query()->where('counterparty_key', $cp)->pluck('id');
        $scopeIds = CostReconciliationScope::query()->where('counterparty_key', $cp)->pluck('id');
        DB::table('cost_invoice_allocations')->whereIn('cost_reconciliation_id', $recIds)->delete();
        DB::table('cost_reconciliation_scopes')->whereIn('id', $scopeIds)->update(['current_reconciliation_id' => null]);
        DB::table('cost_reconciliations')->whereIn('id', $recIds)->delete();
        AuditLog::where('subject_type', (new CostReconciliationScope)->getMorphClass())->whereIn('subject_id', $scopeIds)->delete();
        DB::table('cost_reconciliation_scopes')->whereIn('id', $scopeIds)->delete();
        DB::table('cost_invoice_lines')->where('cost_invoice_id', $invoice->id)->delete();
        DB::table('cost_invoice_events')->where('cost_invoice_id', $invoice->id)->delete();
        AuditLog::where('subject_type', (new CostInvoice)->getMorphClass())->where('subject_id', $invoice->id)->delete();
        DB::table('cost_invoices')->where('id', $invoice->id)->delete();
        fxCleanup([$a, $b]);
        DB::table('ai_providers')->where('key', $cp)->delete();
    }
});

/** Remove every trace of a reporting-currency race so the shared PostgreSQL database is left exactly as it was. */
function rcCleanup(): void
{
    DB::table('app_settings')->where('key', 'finance.reporting_currency')->delete();
    AuditLog::whereIn('action', ['finance.reporting_currency_changed'])->delete();
    AuditLog::where('action', 'settings.updated')->where('subject_type', (new AppSetting)->getMorphClass())->delete();
    app(SettingsRepository::class)->cacheFlush();
}

it('of 6 concurrent reporting-currency changes with NO setting row yet exactly one wins: one row, one value, one FX audit, five stale (E5.2c)', function () {
    rcCleanup(); // the key starts with no stored row at all: this is the first-write race
    $before = app(ReportingCurrencyService::class)->current();
    $target = $before === 'EUR' ? 'GBP' : 'EUR';

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = fxRun(['set-reporting-currency', $target, $before]);
        }
        $outcomes = fxOutcomes($processes);
        app(SettingsRepository::class)->cacheFlush();

        expect(array_filter($outcomes, fn ($o) => $o === 'ok:'.$target))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5) // never last-writer-wins, never "unchanged"
            ->and(DB::table('app_settings')->where('key', 'finance.reporting_currency')->count())->toBe(1)
            ->and(app(ReportingCurrencyService::class)->current())->toBe($target)
            ->and(AuditLog::where('action', 'finance.reporting_currency_changed')->count())->toBe(1)
            ->and(AuditLog::where('action', 'finance.reporting_currency_changed')->first()->metadata['changes']['reporting_currency'])->toBe(['from' => $before, 'to' => $target])
            ->and(AuditLog::where('action', 'settings.updated')->where('subject_type', (new AppSetting)->getMorphClass())->count())->toBe(1);
    } finally {
        rcCleanup();
    }
});

it('of 6 concurrent reporting-currency changes on an EXISTING row exactly one wins: one value, one FX audit, five stale (E5.2c)', function () {
    rcCleanup();
    $start = app(ReportingCurrencyService::class)->current() === 'EUR' ? 'GBP' : 'EUR';
    rcSet($start); // the row now exists — the FOR UPDATE lock is the serialising point
    $target = $start === 'EUR' ? 'GBP' : 'EUR';

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = fxRun(['set-reporting-currency', $target, $start]);
        }
        $outcomes = fxOutcomes($processes);
        app(SettingsRepository::class)->cacheFlush();

        expect(array_filter($outcomes, fn ($o) => $o === 'ok:'.$target))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and(DB::table('app_settings')->where('key', 'finance.reporting_currency')->count())->toBe(1)
            ->and(app(ReportingCurrencyService::class)->current())->toBe($target)
            ->and(AuditLog::where('action', 'finance.reporting_currency_changed')->count())->toBe(2) // the setup change + exactly one winner
            ->and(AuditLog::where('action', 'finance.reporting_currency_changed')->latest('id')->first()->metadata['changes']['reporting_currency'])->toBe(['from' => $start, 'to' => $target]);
    } finally {
        rcCleanup();
    }
});
