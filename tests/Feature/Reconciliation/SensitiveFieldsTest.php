<?php

declare(strict_types=1);

use App\Exceptions\Reconciliation\ReconciliationConflictException;
use App\Models\CostInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase E2 sensitive-field boundaries against the real schema: no free-text,
 * name, address, card or bank column on any E2 table; invoice_ref nullable and
 * unique per counterparty; idempotency mandatory; every reference a bounded token.
 */
it('has no free-text, name, address, card or bank column on any E2 table', function () {
    $forbidden = ['external_note', 'note', 'notes', 'comment', 'description', 'memo', 'vendor_name', 'supplier_name', 'name', 'address', 'email', 'phone', 'pan', 'card_number', 'cvv', 'cvc', 'iban', 'account_number', 'bank_account', 'tax_id', 'vat_number'];

    foreach (['cost_invoices', 'cost_invoice_events', 'cost_invoice_lines', 'cost_reconciliation_scopes', 'cost_reconciliations', 'cost_invoice_allocations', 'cost_adjustments'] as $table) {
        $columns = array_map('strtolower', Schema::getColumnListing($table));
        expect(array_values(array_intersect($columns, $forbidden)))->toBe([], $table);

        foreach ($columns as $column) {
            expect(preg_match('/note|comment|memo|name|address|email|phone|card|cvv|cvc|iban|account_number|tax_id|vat/i', $column))->toBe(0, "{$table}.{$column}");
        }
    }

    expect(Schema::hasColumns('cost_invoices', ['counterparty_key', 'invoice_ref', 'evidence_ref']))->toBeTrue()
        ->and(Schema::hasColumns('cost_invoice_lines', ['description_code']))->toBeTrue()
        ->and(Schema::hasColumns('cost_reconciliations', ['reason_code', 'evidence_ref', 'snapshot_hash']))->toBeTrue()
        ->and(Schema::hasColumns('cost_adjustments', ['reason_code', 'evidence_ref']))->toBeTrue();
});

it('keeps invoice_ref nullable and unique per counterparty, idempotency_key mandatory and unique, and refuses PII-shaped references', function () {
    $columns = collect(Schema::getColumns('cost_invoices'))->keyBy('name');

    expect($columns['invoice_ref']['nullable'])->toBeTrue()
        ->and($columns['idempotency_key']['nullable'])->toBeFalse()
        ->and($columns['counterparty_key']['nullable'])->toBeFalse()
        ->and(Schema::hasIndex('cost_invoices', 'cost_invoices_counterparty_ref_unique'))->toBeTrue()
        ->and(Schema::hasIndex('cost_invoices', ['idempotency_key'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('cost_reconciliation_scopes', 'cost_reconciliation_scopes_scope_unique'))->toBeTrue()
        ->and(Schema::hasIndex('cost_invoice_events', 'cost_invoice_events_one_confirmation'))->toBeTrue();

    $a = e2Invoice(['idempotencyKey' => 'a', 'invoiceRef' => 'INV-1']);
    $b = e2Invoice(['idempotencyKey' => 'b']); // no reference: fine, twice
    $c = e2Invoice(['idempotencyKey' => 'c']);
    $openai = e2Invoice(['idempotencyKey' => 'd', 'counterpartyKey' => 'openai', 'invoiceRef' => 'INV-1']); // same ref, another counterparty: fine

    expect($a->invoice_ref)->toBe('INV-1')->and($b->invoice_ref)->toBeNull()->and($c->invoice_ref)->toBeNull()->and($openai->invoice_ref)->toBe('INV-1')
        ->and(fn () => e2Invoice(['idempotencyKey' => 'e', 'invoiceRef' => 'INV-1']))->toThrow(ReconciliationConflictException::class)
        ->and(CostInvoice::count())->toBe(4);

    foreach (['omar@example.com', 'PS92PALS000000000400123456702', '4111111111111111', "two\nlines", 'Meta Platforms Inc'] as $bad) {
        expect(e2Rule(fn () => e2Invoice(['invoiceRef' => $bad])))->toBe('invoice_ref', $bad)
            ->and(e2Rule(fn () => e2Invoice(['evidenceRef' => $bad])))->toBe('evidence_ref', $bad)
            ->and(e2Rule(fn () => e2Invoice(['component' => 'external', 'counterpartyKey' => $bad])))->toBe('counterparty_key', $bad);
    }
});
