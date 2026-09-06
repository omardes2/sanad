<?php

declare(strict_types=1);

use App\Data\Close\CloseEvaluation;
use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Models\CustomerPayment;
use App\Models\FxConversion;
use App\Models\FxRate;
use App\Services\Close\ClosePreflight;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Payments\CustomerPaymentService;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\CostReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E4 — preflight: the seven cash-basis figures with explicit numbers,
 * and every blocking condition on its own (unknown fee, cash FX, disputes,
 * missing provider / communication / external reconciliation, ledger moved,
 * stale evidence, cost FX, period not ended); informational conditions
 * never block. Reads only.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function preflight(string $month = '2026-08'): CloseEvaluation
{
    return app(ClosePreflight::class)->evaluate($month);
}

it('computes Gross 200 / Refunds 10 / Net 190 / Fees 4 / Net after fees 186 / Cost 55 / Contribution 131 for the closable fixture, with only informational conditions', function () {
    $fx = closableMonth();
    $e = preflight();

    expect($e->canClose())->toBeTrue()
        ->and($e->metrics)->toBe([
            'gross_cash_collected' => '200.00', 'refunds' => '10.00', 'net_cash' => '190.00', 'gateway_fees' => '4.00',
            'net_cash_after_gateway_fees' => '186.00', 'reconciled_service_cost' => '55.000000', 'reconciled_cash_contribution' => '131.000000',
        ])
        ->and(array_column($e->conditions, 'code'))->toBe(['CONFIRMED_ZERO', 'CALCULATED_COVERAGE_PARTIAL', 'CONFIRMED_ZERO', 'CALCULATED_COVERAGE_PARTIAL']) // informational only
        ->and($e->snapshot['expected_providers'])->toBe(['groq'])
        ->and($e->snapshot['payments'])->toHaveCount(2)->and($e->snapshot['gateway_fees'])->toHaveCount(2)->and($e->snapshot['refunds'])->toHaveCount(1)
        ->and($e->snapshot['reconciliations'])->toHaveCount(3)->and($e->snapshot['adjustments'])->toHaveCount(1)
        ->and(strlen($e->inputHash))->toBe(64)
        ->and(preflight()->inputHash)->toBe($e->inputHash); // deterministic

    // The ILS fee follows the payment's exact conversion: same fx_rate_id, snapshot and direction; 3.65 ILS ⇒ 1.00 USD.
    $fee = collect($e->snapshot['gateway_fees'])->firstWhere('payment_id', $fx['ils']->id);
    expect($fee['fx_rate_id'])->toBe($fx['rate']->id)->and($fee['fx_conversion_id'])->toBe($fx['conversion']->id)->and($fee['fx_direction'])->toBe('inverse')
        ->and($fee['fx_rate_snapshot'])->toBe('3.650000000000')->and($fee['amount'])->toBe('3.65')->and($fee['currency'])->toBe('ILS')->and($fee['reporting_amount'])->toBe('1.00');
});

it('blocks on an unknown gateway fee: FEES_INCOMPLETE, net-after-fees and contribution NOT AVAILABLE, never zero', function () {
    closableMonth();
    e1Payment(billingSubscriber(), ['amount' => '20.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-20', 'UTC')]); // fee NULL
    $e = preflight();

    expect($e->canClose())->toBeFalse()->and($e->blocking())->toContain('FEES_INCOMPLETE (payments:'.CustomerPayment::query()->max('id').')')
        ->and($e->metrics['gross_cash_collected'])->toBe('220.00')
        ->and($e->metrics['gateway_fees'])->toBeNull()->and($e->metrics['net_cash_after_gateway_fees'])->toBeNull()->and($e->metrics['reconciled_cash_contribution'])->toBeNull()
        ->and(collect($e->snapshot['gateway_fees'])->firstWhere('status', 'FEES UNKNOWN')['amount'])->toBeNull();
});

it('blocks on cash FX gaps: an unconverted foreign payment or refund makes the fee FX-incomplete too', function () {
    $fx = closableMonth();
    $eur = e1Payment($fx['subscriber'], ['amount' => '50.00', 'currency' => 'EUR', 'receivedAt' => CarbonImmutable::parse('2026-08-21', 'UTC'), 'gatewayFeeAmount' => '1.00', 'feeCurrency' => 'EUR']);
    $e = preflight();

    expect($e->blocking())->toBe(['FX_INCOMPLETE_CASH (payment:'.$eur->id.')'])
        ->and($e->metrics['gross_cash_collected'])->toBeNull()->and($e->metrics['gateway_fees'])->toBeNull()
        ->and(collect($e->snapshot['gateway_fees'])->firstWhere('payment_id', $eur->id)['status'])->toBe('NOT CONVERTED');
});

it('blocks on an unresolved dispute and unblocks once it is resolved', function () {
    $fx = closableMonth();
    $service = app(CustomerPaymentService::class);
    $service->transition($fx['usd'], CustomerPaymentEventType::Disputed, $fx['usd']->stateToken(), PaymentSource::Gateway, 'chargeback');

    expect(preflight()->blocking())->toBe(['UNRESOLVED_DISPUTES (payments:'.$fx['usd']->id.')'])
        ->and(preflight()->metrics['gross_cash_collected'])->toBe('200.00'); // history intact

    $service->transition($fx['usd']->fresh(), CustomerPaymentEventType::DisputeResolved, $fx['usd']->fresh()->stateToken(), PaymentSource::Gateway);
    expect(preflight()->canClose())->toBeTrue();
});

it('derives expected providers from the ledger and requires explicit reconciliation or CONFIRMED ZERO for communication and external — NO PRODUCER is not completeness', function () {
    config(['billing.cost_currency' => 'USD']);
    financeRow(['provider' => 'groq', 'provider_cost' => '1.000000', 'total_cost' => '1.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-15', 'UTC')]);
    financeRow(['provider' => 'openai', 'provider_cost' => '2.000000', 'total_cost' => '2.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-16', 'UTC')]);

    $codes = preflight()->blocking();
    expect($codes)->toBe(['RECONCILIATION_MISSING (provider:groq)', 'RECONCILIATION_MISSING (provider:openai)', 'RECONCILIATION_MISSING (communication (NO PRODUCER is not completeness — record a reconciliation or CONFIRMED ZERO))', 'RECONCILIATION_MISSING (external (NO PRODUCER is not completeness — record a reconciliation or CONFIRMED ZERO))'])
        ->and(preflight()->metrics['reconciled_service_cost'])->toBeNull();

    e2Provider('openai');
    $zero = fn (string $component, string $cp) => e2Reconcile([], ['component' => $component, 'counterpartyKey' => $cp, 'source' => 'confirmed_zero', 'reasonCode' => 'none', 'evidenceRef' => 'att', 'typedConfirmation' => 'ZERO']);
    $zero('provider', 'groq');
    $zero('provider', 'openai');
    $zero('communication', 'meta-whatsapp');
    expect(preflight()->blocking())->toBe(['RECONCILIATION_MISSING (external (NO PRODUCER is not completeness — record a reconciliation or CONFIRMED ZERO))']);
    $zero('external', 'none');
    $e = preflight();
    expect($e->canClose())->toBeTrue()->and($e->metrics['reconciled_service_cost'])->toBe('0.000000')
        ->and(count(array_filter($e->conditions, fn ($c) => $c['code'] === 'CONFIRMED_ZERO')))->toBe(4)
        ->and(count(array_filter($e->conditions, fn ($c) => $c['code'] === 'CALCULATED_COVERAGE_PARTIAL')))->toBe(2); // communication/external: no producer
});

it('blocks when the ledger moved since the current reconciliation', function () {
    $fx = closableMonth();
    financeRow(['provider' => 'groq', 'provider_cost' => '1.000000', 'total_cost' => '1.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-20', 'UTC')]); // after the reconciliation
    expect(preflight()->blocking())->toBe(['LEDGER_MOVED (reconciliation:'.$fx['reconciliation']->id.')']);

    e2Reconcile([], ['expectedCurrentReconciliationId' => $fx['reconciliation']->id, 'source' => 'manual_evidenced', 'reconciledAmount' => '60.000000', 'reasonCode' => 'restated', 'evidenceRef' => 'stmt']);
    expect(preflight()->canClose())->toBeTrue()->and(preflight()->metrics['reconciled_service_cost'])->toBe('60.000000'); // the adjustment belonged to the superseded reconciliation
});

it('blocks on stale evidence (voided or superseded invoice behind the current reconciliation)', function () {
    config(['billing.cost_currency' => 'USD']);
    $invoice = e2ConfirmedInvoice(['service' => '10.000000']);
    $rec = e2Reconcile([[$invoice->lines()->first()->id, '10.000000']]);
    $zero = fn (string $component, string $cp) => e2Reconcile([], ['component' => $component, 'counterpartyKey' => $cp, 'source' => 'confirmed_zero', 'reasonCode' => 'none', 'evidenceRef' => 'att', 'typedConfirmation' => 'ZERO']);
    $zero('communication', 'meta-whatsapp');
    $zero('external', 'none');
    expect(preflight()->canClose())->toBeTrue();

    app(CostInvoiceService::class)->void($invoice->id, $invoice->fresh()->stateToken(), 'duplicate');
    expect(preflight()->blocking())->toBe(['EVIDENCE_STALE (reconciliation:'.$rec->id.' EVIDENCE VOIDED (#'.$invoice->id.'))']);
});

it('blocks on cost FX gaps (a reconciliation or adjustment in another currency without a frozen conversion) and on a period that has not ended', function () {
    config(['billing.cost_currency' => 'USD']);
    $ils = e2ConfirmedInvoice(['service' => '365.000000'], ['currency' => 'ILS', 'component' => 'communication', 'counterpartyKey' => 'meta-whatsapp']);
    $rec = e2Reconcile([[$ils->lines()->first()->id, '365.000000']], ['component' => 'communication', 'counterpartyKey' => 'meta-whatsapp', 'currency' => 'ILS']);
    $zero = fn (string $component, string $cp) => e2Reconcile([], ['component' => $component, 'counterpartyKey' => $cp, 'source' => 'confirmed_zero', 'reasonCode' => 'none', 'evidenceRef' => 'att', 'typedConfirmation' => 'ZERO']);
    $zero('external', 'none');

    expect(preflight()->blocking())->toBe(['FX_INCOMPLETE_COST (reconciliation:'.$rec->id.')']);

    fxConvert('cost_reconciliation', $rec->id, 'USD', fxRate(['rate' => '3.65', 'rateDate' => '2026-09-01'])->id);
    expect(preflight()->canClose())->toBeTrue()->and(preflight()->metrics['reconciled_service_cost'])->toBe('100.000000');

    $adj = app(CostReconciliationService::class)->adjust($rec->id, '-36.500000', 'credit', 'cn');
    expect(preflight()->blocking())->toBe(['FX_INCOMPLETE_COST (adjustment:'.$adj->id.')']);
    fxConvert('cost_adjustment', $adj->id, 'USD', FxRate::query()->firstOrFail()->id); // policy date = reconciliation period_end 2026-09-01
    expect(preflight()->canClose())->toBeTrue()->and(preflight()->metrics['reconciled_service_cost'])->toBe('90.000000');

    expect(preflight('2026-09')->blocking()[0])->toBe('PERIOD_NOT_ENDED (2026-10-01)');
});

it('reflects the current reporting currency: switching it changes statuses, never conversions or figures already frozen elsewhere', function () {
    closableMonth();
    app(ReportingCurrencyService::class)->change('ILS', 'ILS');
    $e = preflight();

    expect($e->reportingCurrency)->toBe('ILS')
        ->and(array_column($e->conditions, 'code'))->toContain('FX_INCOMPLETE_CASH')
        ->and(collect($e->snapshot['payments'])->firstWhere('currency', 'ILS')['status'])->toBe('NATIVE')
        ->and(FxConversion::count())->toBe(1);
});
