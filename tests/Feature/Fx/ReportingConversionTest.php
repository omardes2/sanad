<?php

declare(strict_types=1);

use App\Enums\FxDirection;
use App\Exceptions\Fx\StaleFxException;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Models\AuditLog;
use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Models\FxRate;
use App\Services\Audit\AuditLogger;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Fx\ReportingView;
use App\Support\Audit\AuditActions;
use App\Support\Security\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E3 — frozen reporting conversions: exact fx_rate_id only, policy
 * date must match the quote date, direct / inverse on the same rate row,
 * one rounding at the target scale, native subjects never converted,
 * append-only revisions under a scope pointer, subjects never modified.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('converts a payment with the exact rate id chosen for its received_at date (direct = multiply), and a refund inversely (divide) with the same rate row', function () {
    $ils = billingSubscriber();
    $payment = e1Payment($ils, ['amount' => '100.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $rate = fxRate(['rate' => '3.65', 'rateDate' => '2026-08-10']); // 1 USD = 3.65 ILS

    $conversion = fxConvert('customer_payment', $payment->id, 'ILS', $rate->id);

    expect($conversion->direction)->toBe(FxDirection::Direct)->and($conversion->targetAmountAtScale())->toBe('365.00')->and($conversion->target_currency)->toBe('ILS')
        ->and($conversion->fx_rate_id)->toBe($rate->id)->and($conversion->fx_rate_date->format('Y-m-d'))->toBe('2026-08-10')->and((string) $conversion->rate_snapshot)->toBe('3.650000000000')
        ->and($conversion->sourceAmountAtScale())->toBe('100.00')->and($conversion->source_currency)->toBe('USD')->and($conversion->source_scale)->toBe(2)->and($conversion->target_scale)->toBe(2)
        ->and($conversion->subject_date->utc()->format('Y-m-d H:i:s'))->toBe('2026-08-10 09:00:00')
        ->and((string) $payment->fresh()->amount)->toBe('100.00')->and($payment->fresh()->currency)->toBe('USD') // subject untouched
        ->and(AuditLog::where('action', AuditActions::FxConverted)->first()->metadata['context']['fx_rate_id'])->toBe($rate->id);

    // A refund in ILS reported in USD: QUOTE → BASE = divide, same rate row, no reciprocal stored.
    $ilsPayment = e1Payment($ils, ['amount' => '730.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $refund = e1Refund($ilsPayment, ['amount' => '10.00', 'refundedAt' => CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC')]);
    $inverse = fxConvert('customer_refund', $refund->id, 'USD', $rate->id);

    expect($inverse->direction)->toBe(FxDirection::Inverse)->and($inverse->targetAmountAtScale())->toBe('2.74') // 10 / 3.65 = 2.7397… → 2.74 once
        ->and($inverse->fx_rate_id)->toBe($rate->id)->and(FxRate::count())->toBe(1)->and((string) $inverse->rate_snapshot)->toBe('3.650000000000');
});

it('never looks a rate up: a rate for another date, another pair, a superseded revision, a missing id or a native subject is refused with nothing written', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $usdPayment = e1Payment($subscriber, ['amount' => '50.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $dayBefore = fxRate(['rate' => '3.60', 'rateDate' => '2026-08-09']);
    $eur = fxRate(['baseCurrency' => 'EUR', 'quoteCurrency' => 'USD', 'rate' => '1.10', 'rateDate' => '2026-08-10']);
    $old = fxRate(['rate' => '3.65', 'rateDate' => '2026-08-10']);
    $current = fxRate(['rate' => '3.66', 'rateDate' => '2026-08-10', 'expectedCurrentRateId' => $old->id]);

    expect(fxRule(fn () => fxConvert('customer_payment', $payment->id, 'ILS', $dayBefore->id)))->toBe('FX_RATE_MISSING') // previous day is not a policy
        ->and(fxRule(fn () => fxConvert('customer_payment', $payment->id, 'ILS', $eur->id)))->toBe('FX_RATE_MISSING') // wrong pair
        ->and(fxRule(fn () => fxConvert('customer_payment', $payment->id, 'ILS', 999)))->toBe('FX_RATE_MISSING')
        ->and(fn () => fxConvert('customer_payment', $payment->id, 'ILS', $old->id))->toThrow(StaleFxException::class) // superseded revision
        ->and(fxRule(fn () => fxConvert('customer_payment', $payment->id, 'USD', $current->id)))->toBe('native') // same currency: NATIVE, no rate-1 row
        ->and(fxRule(fn () => fxConvert('customer_payment', $usdPayment->id, 'ILS', $current->id)))->toBe('native')
        ->and(fxRule(fn () => fxConvert('customer_payment', 999, 'ILS', $current->id)))->toBe('subject')
        ->and(fxRule(fn () => fxConvert('provider_invoice', $payment->id, 'ILS', $current->id)))->toBe('subject_type')
        ->and(FxConversion::count())->toBe(0)->and(FxConversionScope::count())->toBe(0);

    $ok = fxConvert('customer_payment', $payment->id, 'ILS', $current->id);
    expect($ok->targetAmountAtScale())->toBe('366.00');
});

it('revises a conversion append-only under its scope pointer; a stale expectation writes nothing; the view shows the current revision', function () {
    $payment = e1Payment(billingSubscriber(), ['amount' => '100.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $r1 = fxRate(['rate' => '3.65']);
    $c1 = fxConvert('customer_payment', $payment->id, 'ILS', $r1->id);
    $scope = FxConversionScope::query()->firstOrFail();

    expect($scope->current_conversion_id)->toBe($c1->id)->and($scope->version)->toBe(1)
        ->and(fn () => fxConvert('customer_payment', $payment->id, 'ILS', $r1->id, ['expectedCurrentConversionId' => null]))->toThrow(StaleFxException::class)
        ->and(FxConversion::count())->toBe(1);

    $r2 = fxRate(['rate' => '3.70', 'expectedCurrentRateId' => $r1->id]); // the quote was corrected → the old conversion still stands until revised
    expect(fn () => fxConvert('customer_payment', $payment->id, 'ILS', $r1->id, ['expectedCurrentConversionId' => $c1->id]))->toThrow(StaleFxException::class); // r1 superseded
    $c2 = fxConvert('customer_payment', $payment->id, 'ILS', $r2->id, ['expectedCurrentConversionId' => $c1->id, 'reasonCode' => 'rate_corrected']);
    $scope->refresh();

    expect($c2->supersedes_id)->toBe($c1->id)->and($c2->targetAmountAtScale())->toBe('370.00')
        ->and($scope->current_conversion_id)->toBe($c2->id)->and($scope->version)->toBe(2)
        ->and(FxConversion::count())->toBe(2)->and($c1->fresh()->targetAmountAtScale())->toBe('365.00') // history intact
        ->and(fn () => $c1->forceFill(['target_amount' => '1'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $c1->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $scope->forceFill(['target_currency' => 'EUR'])->save())->toThrow(ImmutableFinancialRecordException::class);

    app(ReportingCurrencyService::class)->change('ILS', 'ILS');
    $view = app(ReportingView::class)->cash(CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'));
    expect($view['lines'][0]->status)->toBe('CONVERTED')->and($view['lines'][0]->targetAmount)->toBe('370.00')->and($view['lines'][0]->conversionId)->toBe($c2->id)->and($view['lines'][0]->fxRateId)->toBe($r2->id);
});

it('converts a cost reconciliation on its period_end policy date at scale 6, and is atomic with the audit entry', function () {
    e2Provider();
    $inv = e2ConfirmedInvoice(['service' => '100.000000']);
    $rec = e2Reconcile([[$inv->lines()->first()->id, '100.000000']]); // USD, August ⇒ period_end 2026-09-01
    $rate = fxRate(['rate' => '3.654321', 'rateDate' => '2026-09-01']);
    $wrong = fxRate(['rate' => '3.65', 'rateDate' => '2026-08-31']);

    expect(fxRule(fn () => fxConvert('cost_reconciliation', $rec->id, 'ILS', $wrong->id)))->toBe('FX_RATE_MISSING');
    $conversion = fxConvert('cost_reconciliation', $rec->id, 'ILS', $rate->id);
    expect($conversion->targetAmountAtScale())->toBe('365.432100')->and($conversion->source_scale)->toBe(6)->and($conversion->target_scale)->toBe(6)
        ->and($conversion->subject_date->utc()->format('Y-m-d'))->toBe('2026-09-01');

    $payment = e1Payment(billingSubscriber(), ['amount' => '1.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-09-01 09:00:00', 'UTC')]);
    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });
    expect(fn () => fxConvert('customer_payment', $payment->id, 'ILS', $rate->id))->toThrow(RuntimeException::class);
    expect(FxConversion::count())->toBe(1)->and(FxConversionScope::count())->toBe(1);
});
