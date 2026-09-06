<?php

declare(strict_types=1);

use App\Exceptions\Reconciliation\StaleReconciliationException;
use App\Models\AuditLog;
use App\Models\CostInvoiceAllocation;
use App\Models\CostReconciliation;
use App\Support\Audit\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E3 — allocation-level FX in reconciliation evidence: a cross-currency
 * invoice share needs the exact fx_rate_id dated on invoice.issued_at, each
 * allocation freezes its own conversion, the line cap is enforced on the
 * SOURCE share, `amount` is the converted scope-currency value, and native
 * shares carry no rate. No whole-invoice conversion, no lookup.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('requires an explicit rate dated on the invoice issued_at for a cross-currency line, freezes it per allocation and caps on the source share', function () {
    $eur = e2ConfirmedInvoice(['service' => '100.000000'], ['currency' => 'EUR', 'issuedAt' => CarbonImmutable::parse('2026-09-02', 'UTC')]);
    $line = $eur->lines()->firstOrFail();
    fxPair('EUR', 'USD');
    $wrongDay = fxRate(['baseCurrency' => 'EUR', 'quoteCurrency' => 'USD', 'rate' => '1.10', 'rateDate' => '2026-09-01']);
    $rate = fxRate(['baseCurrency' => 'EUR', 'quoteCurrency' => 'USD', 'rate' => '1.100000000000', 'rateDate' => '2026-09-02']);

    expect(e2Rule(fn () => e2Reconcile([[$line->id, '60.000000']])))->toBe('FX_REQUIRED') // no rate named
        ->and(e2Rule(fn () => e2Reconcile([[$line->id, '60.000000', $wrongDay->id]])))->toBe('FX_RATE_MISSING') // issued 09-02, quote 09-01
        ->and(e2Rule(fn () => e2Reconcile([[$line->id, '60.000000', 999]])))->toBe('FX_RATE_MISSING')
        ->and(CostReconciliation::count())->toBe(0);

    $august = e2Reconcile([[$line->id, '60.000000', $rate->id]]);
    $allocation = CostInvoiceAllocation::query()->firstOrFail();

    expect((string) $august->reconciled_amount)->toBe('66.000000') // 60 EUR × 1.1 in USD
        ->and($allocation->fxStatus())->toBe('CONVERTED')
        ->and((string) $allocation->source_amount)->toBe('60.000000')->and($allocation->source_currency)->toBe('EUR')
        ->and((string) $allocation->amount)->toBe('66.000000')->and($allocation->currency)->toBe('USD')
        ->and($allocation->fx_rate_id)->toBe($rate->id)->and((string) $allocation->fx_rate_snapshot)->toBe('1.100000000000')
        ->and($allocation->fx_direction->value)->toBe('direct')->and($allocation->fx_rate_date->format('Y-m-d'))->toBe('2026-09-02');

    $audit = AuditLog::where('action', AuditActions::CostReconciled)->first()->metadata['context']['evidence_fx'][0];
    expect($audit['invoice_issued_at'])->toBe('2026-09-02')->and($audit['fx_rate_date'])->toBe('2026-09-02')->and($audit['fx_rate_id'])->toBe($rate->id)
        ->and($audit['fx_direction'])->toBe('direct')->and($audit['source_amount'])->toBe('60.000000')->and($audit['converted_amount'])->toBe('66.000000');

    // The cap is on the SOURCE share (EUR): 40 EUR remain, so 40.000001 EUR is refused even though 44 USD "looks" different.
    expect(e2Rule(fn () => e2Reconcile([[$line->id, '40.000001', $rate->id]], ['month' => '2026-09'])))->toBe('allocation_limit');
    $september = e2Reconcile([[$line->id, '40.000000', $rate->id]], ['month' => '2026-09']);
    expect((string) $september->reconciled_amount)->toBe('44.000000')
        ->and(CostInvoiceAllocation::query()->sum('source_amount'))->toEqual(100)
        ->and(e2Rule(fn () => e2Reconcile([[$line->id, '0.000001', $rate->id]], ['month' => '2026-10'])))->toBe('allocation_limit');
});

it('keeps native shares rate-free, mixes native and converted evidence in one reconciliation, and refuses a superseded rate as stale', function () {
    $usd = e2ConfirmedInvoice(['service' => '50.000000']);
    $eur = e2ConfirmedInvoice(['service' => '100.000000', 'credit' => '-10.000000'], ['currency' => 'EUR', 'issuedAt' => CarbonImmutable::parse('2026-09-02', 'UTC')]);
    [$eurService, $eurCredit] = $eur->lines()->orderBy('line_no')->get()->all();
    $usdLine = $usd->lines()->firstOrFail();
    fxPair('EUR', 'USD');
    $old = fxRate(['baseCurrency' => 'EUR', 'quoteCurrency' => 'USD', 'rate' => '1.10', 'rateDate' => '2026-09-02']);
    $current = fxRate(['baseCurrency' => 'EUR', 'quoteCurrency' => 'USD', 'rate' => '1.20', 'rateDate' => '2026-09-02', 'expectedCurrentRateId' => $old->id]);

    expect(fn () => e2Reconcile([[$eurService->id, '10.000000', $old->id]]))->toThrow(StaleReconciliationException::class);

    $rec = e2Reconcile([[$usdLine->id, '50.000000'], [$eurService->id, '10.000000', $current->id], [$eurCredit->id, '-5.000000', $current->id]]);
    $rows = CostInvoiceAllocation::query()->orderBy('id')->get();

    expect((string) $rec->reconciled_amount)->toBe('56.000000') // 50 + 12 − 6
        ->and($rows[0]->fxStatus())->toBe('NATIVE')->and($rows[0]->fx_rate_id)->toBeNull()->and((string) $rows[0]->source_amount)->toBe('50.000000')->and($rows[0]->source_currency)->toBe('USD')
        ->and($rows[1]->fxStatus())->toBe('CONVERTED')->and((string) $rows[1]->amount)->toBe('12.000000')
        ->and($rows[2]->fxStatus())->toBe('CONVERTED')->and((string) $rows[2]->amount)->toBe('-6.000000')->and((string) $rows[2]->source_amount)->toBe('-5.000000');

    // A rate given for a native line is ignored? No — it must not be needed; passing one is refused as a policy mismatch only if it is wrong. Passing none is the native path.
    expect(e2Rule(fn () => e2Reconcile([[$usdLine->id, '0.000001']], ['month' => '2026-09'])))->toBe('allocation_limit'); // native cap still on source
});
