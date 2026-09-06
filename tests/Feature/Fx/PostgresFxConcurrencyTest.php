<?php

declare(strict_types=1);

use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\CostInvoice;
use App\Models\CostInvoiceAllocation;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Models\CustomerPayment;
use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Models\FxPair;
use App\Models\FxRate;
use App\Models\FxRateScope;
use App\Models\User;
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

function fxRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:fx-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/** @return list<string> */
function fxOutcomes(array $processes): array
{
    $outcomes = [];
    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

/** Two synthetic currency codes nobody else uses, so cleanup touches only this test's rows. */
function fxCodes(): array
{
    $letters = static fn (): string => chr(random_int(65, 90)).chr(random_int(65, 90));
    $a = 'X'.$letters();
    $b = 'Y'.$letters();

    return [$a, $b];
}

function fxCleanup(array $codes, ?User $user = null): void
{
    $pairIds = FxPair::query()->whereIn('base_currency', $codes)->orWhereIn('quote_currency', $codes)->pluck('id');
    $rateIds = FxRate::query()->whereIn('fx_pair_id', $pairIds)->pluck('id');
    $convIds = FxConversion::query()->whereIn('fx_rate_id', $rateIds)->pluck('id');
    $convScopeIds = FxConversion::query()->whereIn('id', $convIds)->pluck('scope_id');
    DB::table('fx_conversion_scopes')->whereIn('id', $convScopeIds)->update(['current_conversion_id' => null]);
    DB::table('fx_conversions')->whereIn('id', $convIds)->delete();
    DB::table('fx_conversion_scopes')->whereIn('id', $convScopeIds)->delete();
    DB::table('cost_invoice_allocations')->whereIn('fx_rate_id', $rateIds)->delete();
    DB::table('fx_rate_scopes')->whereIn('fx_pair_id', $pairIds)->update(['current_rate_id' => null]);
    DB::table('fx_rates')->whereIn('id', $rateIds)->delete();
    AuditLog::where('subject_type', (new FxRateScope)->getMorphClass())->whereIn('subject_id', FxRateScope::query()->whereIn('fx_pair_id', $pairIds)->pluck('id'))->delete();
    DB::table('fx_rate_scopes')->whereIn('fx_pair_id', $pairIds)->delete();
    AuditLog::where('subject_type', (new FxPair)->getMorphClass())->whereIn('subject_id', $pairIds)->delete();
    DB::table('fx_pairs')->whereIn('id', $pairIds)->delete();

    if ($user !== null) {
        $paymentIds = CustomerPayment::query()->where('subscriber_id', $user->id)->pluck('id');
        DB::table('customer_payment_events')->whereIn('customer_payment_id', $paymentIds)->delete();
        AuditLog::where('subject_type', (new CustomerPayment)->getMorphClass())->whereIn('subject_id', $paymentIds)->delete();
        DB::table('customer_payments')->whereIn('id', $paymentIds)->delete();
        $user->delete();
    }
}

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
