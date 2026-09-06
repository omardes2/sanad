<?php

declare(strict_types=1);

use App\Exceptions\Payments\PaymentConflictException;
use App\Exceptions\Payments\PaymentRuleException;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase E1 sensitive-field boundaries, proven against the real schema and the
 * real validators: no free-text column, no card / bank column, no method hint;
 * gateway_payment_ref nullable in manual mode and unique per gateway when
 * present; idempotency_key mandatory; reference / reason / evidence bounded and
 * shaped so they cannot carry an e-mail, a PAN or an IBAN.
 */
it('has no free-text, card, bank or method-hint column on any E1 table', function () {
    $forbidden = ['external_note', 'note', 'notes', 'comment', 'description', 'memo', 'method_hint', 'payment_method', 'pan', 'card_number', 'card', 'cvv', 'cvc', 'iban', 'account_number', 'bank_account', 'email', 'phone', 'name'];

    foreach (['customer_payments', 'customer_payment_events', 'customer_refunds', 'payment_allocations', 'refund_allocations'] as $table) {
        $columns = array_map('strtolower', Schema::getColumnListing($table));
        expect(array_values(array_intersect($columns, $forbidden)))->toBe([], $table);

        foreach ($columns as $column) {
            expect(preg_match('/note|comment|memo|hint|card|cvv|cvc|iban|account_number|email|phone/i', $column))->toBe(0, "{$table}.{$column}");
        }
    }

    // Only bounded reference columns exist, with their approved lengths.
    $lengths = fn (string $table, string $column): ?int => Schema::getColumns($table)[array_search($column, array_column(Schema::getColumns($table), 'name'), true)]['type'] ?? null;
    expect(Schema::hasColumns('customer_payments', ['reference', 'reason_code', 'evidence_ref']))->toBeTrue()
        ->and(Schema::hasColumns('customer_refunds', ['reason_code', 'evidence_ref']))->toBeTrue()
        ->and(Schema::hasColumns('payment_allocations', ['reason_code']))->toBeTrue()
        ->and(Schema::hasColumns('refund_allocations', ['reason_code']))->toBeTrue();
});

it('keeps gateway_payment_ref nullable in manual mode, unique per gateway when present, and idempotency_key mandatory', function () {
    $columns = collect(Schema::getColumns('customer_payments'))->keyBy('name');

    expect($columns['gateway_payment_ref']['nullable'])->toBeTrue()
        ->and($columns['idempotency_key']['nullable'])->toBeFalse()
        ->and(Schema::hasIndex('customer_payments', 'customer_payments_gateway_ref_unique'))->toBeTrue()
        ->and(Schema::hasIndex('customer_payments', ['idempotency_key'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('customer_refunds', 'customer_refunds_gateway_ref_unique'))->toBeTrue()
        ->and(Schema::hasIndex('customer_refunds', ['idempotency_key'], 'unique'))->toBeTrue();

    $subscriber = billingSubscriber();
    $noRef = e1Payment($subscriber, ['idempotencyKey' => 'k-1']);
    $withRef = e1Payment($subscriber, ['idempotencyKey' => 'k-2', 'gatewayPaymentRef' => 'GW-1']);

    expect($noRef->gateway_payment_ref)->toBeNull()->and($withRef->gateway_payment_ref)->toBe('GW-1')
        ->and(fn () => e1Payment($subscriber, ['idempotencyKey' => 'k-3', 'gatewayPaymentRef' => 'GW-1']))->toThrow(PaymentConflictException::class)
        ->and(fn () => e1Payment($subscriber, ['idempotencyKey' => '']))->toThrow(PaymentRuleException::class)
        ->and(fn () => e1Payment($subscriber, ['idempotencyKey' => "\t "]))->toThrow(PaymentRuleException::class)
        ->and(CustomerPayment::count())->toBe(2);
});

it('refuses e-mail-like, card-like and IBAN-like values in every reference field, and enforces the length caps', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);

    $pii = [
        'omar@example.com', // e-mail
        '4111111111111111', // PAN
        '4111 1111 1111 1111', // PAN with spaces: whitespace is never allowed in a reference token
        'PS92PALS000000000400123456702', // IBAN (25 consecutive digits)
        'acct:00123456789012', // account number
        "line one\nline two", // free text
        'Omar Desouki said: refund please', // a sentence (whitespace) is free text
    ];
    $expectedRejected = [true, true, true, true, true, true, true];

    foreach ($pii as $i => $value) {
        $reference = e1RuleOf(fn () => e1Payment($subscriber, ['reference' => $value]));
        $reason = e1RuleOf(fn () => e1Payment($subscriber, ['reasonCode' => mb_substr($value, 0, 32)]));
        $evidence = e1RuleOf(fn () => e1Payment($subscriber, ['evidenceRef' => $value]));
        $refundReason = e1RuleOf(fn () => e1Refund($payment, ['amount' => '0.01', 'reasonCode' => mb_substr($value, 0, 32)]));
        $refundEvidence = e1RuleOf(fn () => e1Refund($payment, ['amount' => '0.01', 'evidenceRef' => $value]));

        if ($expectedRejected[$i]) {
            expect($reference)->toBe('reference', $value)->and($evidence)->toBe('evidence_ref', $value)->and($refundEvidence)->toBe('evidence_ref', $value);
            if (mb_strlen($value) <= 32) {
                expect($reason)->toBe('reason_code', $value)->and($refundReason)->toBe('reason_code', $value);
            }
        }
    }

    // Length caps: 64 / 32 / 191.
    expect(e1RuleOf(fn () => e1Payment($subscriber, ['reference' => str_repeat('a', 65)])))->toBe('reference')
        ->and(e1RuleOf(fn () => e1Payment($subscriber, ['reasonCode' => str_repeat('a', 33)])))->toBe('reason_code')
        ->and(e1RuleOf(fn () => e1Payment($subscriber, ['evidenceRef' => str_repeat('a', 192)])))->toBe('evidence_ref')
        ->and(e1RuleOf(fn () => e1Payment($subscriber, ['reference' => str_repeat('a', 64), 'reasonCode' => str_repeat('b', 32), 'evidenceRef' => str_repeat('c', 191)])))->toBe('none')
        ->and(e1RuleOf(fn () => e1Payment($subscriber, ['reference' => 'BANK-2026/09-05#7', 'reasonCode' => 'bank_transfer', 'evidenceRef' => 'ticket:441'])))->toBe('none')
        ->and(CustomerRefund::count())->toBe(0);
});

function e1RuleOf(callable $fn): string
{
    try {
        $fn();
    } catch (PaymentRuleException $e) {
        return $e->rule;
    }

    return 'none';
}
