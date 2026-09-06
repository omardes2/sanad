<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\FxConversion;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Fx\ReportingView;
use App\Support\Audit\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E3 — the reporting-currency view: NATIVE without a rate, CONVERTED
 * with the exact frozen rate, NOT CONVERTED otherwise; totals only when every
 * line qualifies (else INCOMPLETE / NOT AVAILABLE); originals always shown;
 * changing the reporting currency rewrites nothing.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('defaults the reporting currency to billing.cost_currency and changes it only with the typed code, audited, without touching conversions', function () {
    config(['billing.cost_currency' => 'USD']);
    $service = app(ReportingCurrencyService::class);
    expect($service->current())->toBe('USD');

    expect(fxRule(fn () => $service->change('ILS', 'ils')))->toBe('typed_confirmation')
        ->and(fxRule(fn () => $service->change('ILS', '')))->toBe('typed_confirmation')
        ->and(fxRule(fn () => $service->change('USD', 'USD')))->toBe('unchanged')
        ->and(fxRule(fn () => $service->change('IL', 'IL')))->toBe('reporting_currency');

    $payment = e1Payment(billingSubscriber(), ['amount' => '100.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-10', 'UTC')]);
    $conversion = fxConvert('customer_payment', $payment->id, 'ILS', fxRate(['rate' => '3.65'])->id);

    expect($service->change('ILS', 'ILS', 'board_decision'))->toBe('ILS')->and($service->current())->toBe('ILS')
        ->and(AuditLog::where('action', AuditActions::FinanceReportingCurrencyChanged)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::FinanceReportingCurrencyChanged)->first()->metadata['changes']['reporting_currency'])->toBe(['from' => 'USD', 'to' => 'ILS'])
        ->and(AuditLog::where('action', AuditActions::FinanceReportingCurrencyChanged)->first()->metadata['context']['conversions_recomputed'])->toBe(0)
        ->and($conversion->fresh()->targetAmountAtScale())->toBe('365.00')->and(FxConversion::count())->toBe(1);
});

it('shows NATIVE, CONVERTED and NOT CONVERTED lines with the originals, and a total only when every line qualifies', function () {
    config(['billing.cost_currency' => 'USD']);
    $s = billingSubscriber();
    $usd = e1Payment($s, ['amount' => '100.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $ilsConverted = e1Payment($s, ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC')]);
    $ilsMissing = e1Payment($s, ['amount' => '73.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-11 10:00:00', 'UTC')]);
    $refund = e1Refund($usd, ['amount' => '10.00', 'refundedAt' => CarbonImmutable::parse('2026-08-12 10:00:00', 'UTC')]);
    $rate = fxRate(['rate' => '3.65', 'rateDate' => '2026-08-10']);
    fxConvert('customer_payment', $ilsConverted->id, 'USD', $rate->id);

    $view = app(ReportingView::class)->cash(CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'));
    $byId = collect($view['lines'])->keyBy(fn ($l) => $l->subjectType.':'.$l->subjectId);

    expect($view['currency'])->toBe('USD')
        ->and($byId['customer_payment:'.$usd->id]->status)->toBe('NATIVE')->and($byId['customer_payment:'.$usd->id]->fxRateId)->toBeNull()->and($byId['customer_payment:'.$usd->id]->reportingAmount())->toBe('100.00')
        ->and($byId['customer_payment:'.$ilsConverted->id]->status)->toBe('CONVERTED')->and($byId['customer_payment:'.$ilsConverted->id]->targetAmount)->toBe('100.00')->and($byId['customer_payment:'.$ilsConverted->id]->fxRateId)->toBe($rate->id)->and($byId['customer_payment:'.$ilsConverted->id]->direction)->toBe('inverse')
        ->and($byId['customer_payment:'.$ilsConverted->id]->sourceAmount)->toBe('365.00')->and($byId['customer_payment:'.$ilsConverted->id]->sourceCurrency)->toBe('ILS') // original always shown
        ->and($byId['customer_payment:'.$ilsMissing->id]->status)->toBe('NOT CONVERTED')->and($byId['customer_payment:'.$ilsMissing->id]->reportingAmount())->toBeNull()
        ->and($byId['customer_refund:'.$refund->id]->status)->toBe('NATIVE')
        ->and($view['totals']['gross']->amount)->toBeNull()->and($view['totals']['gross']->status())->toBe('INCOMPLETE / NOT AVAILABLE')->and($view['totals']['gross']->notConverted)->toBe(1)
        ->and($view['totals']['refunds']->amount)->toBe('10.00') // every refund line qualifies
        ->and($view['totals']['net']->amount)->toBeNull(); // gross incomplete ⇒ net incomplete

    // Converting the missing one completes the totals: 100 + 100 + 20 = 220 gross, 10 refunds, 210 net.
    fxConvert('customer_payment', $ilsMissing->id, 'USD', fxRate(['rate' => '3.65', 'rateDate' => '2026-08-11'])->id);
    $view = app(ReportingView::class)->cash(CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'));
    expect($view['totals']['gross']->amount)->toBe('220.00')->and($view['totals']['gross']->native)->toBe(1)->and($view['totals']['gross']->converted)->toBe(2)
        ->and($view['totals']['refunds']->amount)->toBe('10.00')->and($view['totals']['net']->amount)->toBe('210.00');

    // Switching the reporting currency: the USD lines become NOT CONVERTED (no ILS conversion exists), the ILS ones NATIVE; nothing recomputed.
    app(ReportingCurrencyService::class)->change('ILS', 'ILS');
    $view = app(ReportingView::class)->cash(CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'));
    $byId = collect($view['lines'])->keyBy(fn ($l) => $l->subjectType.':'.$l->subjectId);
    expect($byId['customer_payment:'.$usd->id]->status)->toBe('NOT CONVERTED')->and($byId['customer_payment:'.$ilsConverted->id]->status)->toBe('NATIVE')
        ->and($byId['customer_payment:'.$ilsConverted->id]->reportingAmount())->toBe('365.00')->and($view['totals']['gross']->amount)->toBeNull()
        ->and(FxConversion::count())->toBe(2);
});

it('reports reconciled cost per current reconciliation with the same rules, never summing native currencies together', function () {
    config(['billing.cost_currency' => 'USD']);
    $usd = e2ConfirmedInvoice(['service' => '100.000000']);
    $usdRec = e2Reconcile([[$usd->lines()->first()->id, '100.000000']]);
    $ils = e2ConfirmedInvoice(['service' => '365.000000'], ['currency' => 'ILS', 'counterpartyKey' => 'meta-whatsapp', 'component' => 'communication']);
    $ilsRec = e2Reconcile([[$ils->lines()->first()->id, '365.000000']], ['component' => 'communication', 'counterpartyKey' => 'meta-whatsapp', 'currency' => 'ILS']);

    $view = app(ReportingView::class)->cost('2026-08', '2026-08');
    expect($view['lines'])->toHaveCount(2)->and($view['totals']['base']->amount)->toBeNull()->and($view['totals']['base']->notConverted)->toBe(1);

    fxConvert('cost_reconciliation', $ilsRec->id, 'USD', fxRate(['rate' => '3.65', 'rateDate' => '2026-09-01'])->id);
    $view = app(ReportingView::class)->cost('2026-08', '2026-08');
    $ilsLine = collect($view['lines'])->firstWhere('subjectId', $ilsRec->id);
    expect($ilsLine->status)->toBe('CONVERTED')->and($ilsLine->targetAmount)->toBe('100.000000')->and($ilsLine->direction)->toBe('inverse')
        ->and($view['totals']['base']->amount)->toBe('200.000000');
});
