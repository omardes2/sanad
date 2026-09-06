<?php

declare(strict_types=1);

use App\Enums\CostInvoiceEventType;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Exceptions\Reconciliation\ReconciliationConflictException;
use App\Exceptions\Reconciliation\StaleReconciliationException;
use App\Models\AuditLog;
use App\Models\CostInvoice;
use App\Models\CostInvoiceEvent;
use App\Models\CostInvoiceLine;
use App\Services\Audit\AuditLogger;
use App\Services\Reconciliation\CostInvoiceService;
use App\Support\Audit\AuditActions;
use App\Support\Security\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E2 — supplier invoices as EVIDENCE: idempotent drafts, bounded
 * counterparty keys, signed lines with an explicit sign contract, exact
 * Σ lines = total at confirmation, stale-safe append-only lifecycle, frozen
 * facts and lines after confirmation, one confirmation per invoice, atomic audit.
 */
it('records a draft idempotently, refuses different facts under the same key and the same reference under another key, and requires a known AI provider for the provider component', function () {
    $first = e2Invoice(['idempotencyKey' => 'k1', 'invoiceRef' => 'INV-2026-08']);
    $again = e2Invoice(['idempotencyKey' => 'k1', 'invoiceRef' => 'INV-2026-08']);

    expect($first->current_status)->toBe(CostInvoiceEventType::Draft)->and($first->stateToken())->toBe('i:'.$first->latest_event_id)
        ->and($again->id)->toBe($first->id)->and($again->wasRecentlyCreated)->toBeFalse()
        ->and(CostInvoice::count())->toBe(1)->and(CostInvoiceEvent::count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::CostInvoiceRecorded)->count())->toBe(1)
        ->and(fn () => e2Invoice(['idempotencyKey' => 'k1', 'invoiceRef' => 'INV-2026-08', 'totalAmount' => '100.000001']))->toThrow(ReconciliationConflictException::class)
        ->and(fn () => e2Invoice(['idempotencyKey' => 'k2', 'invoiceRef' => 'INV-2026-08']))->toThrow(ReconciliationConflictException::class)
        ->and(e2Rule(fn () => e2Invoice(['counterpartyKey' => 'unknown-llm'])))->toBe('counterparty_key')
        ->and(e2Rule(fn () => e2Invoice(['component' => 'communication', 'counterpartyKey' => 'Omar Shahin'])))->toBe('counterparty_key') // no names, no whitespace
        ->and(e2Rule(fn () => e2Invoice(['component' => 'communication', 'counterpartyKey' => 'billing@meta.com'])))->toBe('counterparty_key')
        ->and(e2Rule(fn () => e2Invoice(['component' => 'hosting', 'counterpartyKey' => 'aws'])))->toBe('component')
        ->and(e2Rule(fn () => e2Invoice(['idempotencyKey' => ' '])))->toBe('idempotency_key')
        ->and(e2Rule(fn () => e2Invoice(['issuedAt' => CarbonImmutable::now('UTC')->addDay()])))->toBe('issued_at')
        ->and(e2Rule(fn () => e2Invoice(['periodEnd' => CarbonImmutable::parse('2026-08-01', 'UTC')])))->toBe('period')
        ->and(e2Rule(fn () => e2Invoice(['currency' => 'dollars'])))->toBe('currency');

    // Several invoices for the same counterparty and period are fine; a communication supplier needs no AI provider.
    e2Invoice(['idempotencyKey' => 'k3']);
    $meta = e2Invoice(['idempotencyKey' => 'k4', 'component' => 'communication', 'counterpartyKey' => 'meta-whatsapp']);
    expect(CostInvoice::count())->toBe(3)->and($meta->component->value)->toBe('communication')->and($meta->invoice_ref)->toBeNull();
});

it('enforces the line sign contract (service/tax/other >= 0, credit <= 0), unique line numbers, drafts only, and PostgreSQL CHECKs', function () {
    $invoice = e2Invoice(['totalAmount' => '90.000000']);

    $service = e2Line($invoice, ['lineNo' => 1, 'kind' => 'service', 'amount' => '100.000000']);
    $tax = e2Line($invoice, ['lineNo' => 2, 'kind' => 'tax', 'amount' => '16.000000']);
    $credit = e2Line($invoice, ['lineNo' => 3, 'kind' => 'credit', 'amount' => '-26.000000']);

    expect($service->currency)->toBe('USD')->and((string) $credit->amount)->toBe('-26.000000')->and((string) $tax->amount)->toBe('16.000000')
        ->and(e2Rule(fn () => e2Line($invoice, ['lineNo' => 4, 'kind' => 'service', 'amount' => '-1.000000'])))->toBe('sign')
        ->and(e2Rule(fn () => e2Line($invoice, ['lineNo' => 4, 'kind' => 'tax', 'amount' => '-1.000000'])))->toBe('sign')
        ->and(e2Rule(fn () => e2Line($invoice, ['lineNo' => 4, 'kind' => 'credit', 'amount' => '1.000000'])))->toBe('sign')
        ->and(e2Rule(fn () => e2Line($invoice, ['lineNo' => 4, 'kind' => 'service', 'amount' => '0'])))->toBe('amount')
        ->and(e2Rule(fn () => e2Line($invoice, ['lineNo' => 4, 'kind' => 'discount', 'amount' => '1'])))->toBe('kind')
        ->and(e2Rule(fn () => e2Line($invoice, ['lineNo' => 1, 'kind' => 'service', 'amount' => '1'])))->toBe('line_no')
        ->and(e2Rule(fn () => e2Line($invoice, ['lineNo' => 4, 'descriptionCode' => 'free text here'])))->toBe('description_code')
        ->and(e2Rule(fn () => e2Line($invoice, ['lineNo' => 4, 'periodStart' => CarbonImmutable::parse('2026-08-01', 'UTC')])))->toBe('period')
        ->and(CostInvoiceLine::count())->toBe(3)
        ->and(AuditLog::where('action', AuditActions::CostInvoiceLineAdded)->count())->toBe(3);

    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(fn () => DB::transaction(fn () => DB::table('cost_invoice_lines')->insert(['cost_invoice_id' => $invoice->id, 'line_no' => 9, 'kind' => 'credit', 'amount' => '5.000000', 'currency' => 'USD', 'description_code' => 'x', 'actor_ref' => 'console', 'created_at' => now()])))->toThrow(QueryException::class);
    }
});

it('confirms only when Σ signed lines equals the signed total exactly, freezes facts and lines, and refuses a second confirmation at the service and database level', function () {
    $invoice = e2Invoice(['totalAmount' => '90.000000']);
    e2Line($invoice, ['lineNo' => 1, 'kind' => 'service', 'amount' => '100.000000']);
    e2Line($invoice, ['lineNo' => 2, 'kind' => 'tax', 'amount' => '16.000000']);
    $service = app(CostInvoiceService::class);

    expect(e2Rule(fn () => $service->confirm($invoice->id, $invoice->fresh()->stateToken())))->toBe('total_mismatch'); // 116 ≠ 90
    e2Line($invoice, ['lineNo' => 3, 'kind' => 'credit', 'amount' => '-26.000001']);
    expect(e2Rule(fn () => $service->confirm($invoice->id, $invoice->fresh()->stateToken())))->toBe('total_mismatch'); // off by 0.000001

    $fixed = e2Invoice(['totalAmount' => '90.000000']);
    e2Line($fixed, ['lineNo' => 1, 'kind' => 'service', 'amount' => '100.000000']);
    e2Line($fixed, ['lineNo' => 2, 'kind' => 'tax', 'amount' => '16.000000']);
    e2Line($fixed, ['lineNo' => 3, 'kind' => 'credit', 'amount' => '-26.000000']);
    $seen = $fixed->fresh()->stateToken();

    $confirmed = $service->confirm($fixed->id, $seen, 'pdf:2026-08');
    expect($confirmed->current_status)->toBe(CostInvoiceEventType::Confirmed)->and($confirmed->isConfirmed())->toBeTrue()
        ->and(CostInvoiceEvent::query()->where('cost_invoice_id', $fixed->id)->orderBy('id')->pluck('event_type')->map(fn ($e) => $e->value)->all())->toBe(['draft', 'confirmed'])
        ->and(AuditLog::where('action', AuditActions::CostInvoiceTransitioned)->where('subject_id', $fixed->id)->first()->metadata['changes']['current_status'])->toBe(['from' => 'draft', 'to' => 'confirmed']);

    // Frozen: no more lines, no fact edits, no deletes; a stale token or a second confirmation is refused.
    expect(e2Rule(fn () => e2Line($fixed, ['lineNo' => 4, 'amount' => '1'])))->toBe('lifecycle')
        ->and(fn () => $fixed->fresh()->forceFill(['total_amount' => '1.000000'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $fixed->fresh()->forceFill(['counterparty_key' => 'openai'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $fixed->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => CostInvoiceLine::query()->where('cost_invoice_id', $fixed->id)->first()->forceFill(['amount' => '1'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => CostInvoiceLine::query()->where('cost_invoice_id', $fixed->id)->first()->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $service->confirm($fixed->id, $seen))->toThrow(StaleReconciliationException::class)
        ->and(e2Rule(fn () => $service->confirm($fixed->id, $confirmed->stateToken())))->toBe('lifecycle')
        ->and(fn () => DB::transaction(fn () => DB::table('cost_invoice_events')->insert(['cost_invoice_id' => $fixed->id, 'event_type' => 'confirmed', 'occurred_at' => now(), 'actor_ref' => 'console', 'created_at' => now()])))->toThrow(QueryException::class)
        ->and(CostInvoiceEvent::query()->where('cost_invoice_id', $fixed->id)->where('event_type', 'confirmed')->count())->toBe(1)
        ->and((string) $fixed->fresh()->total_amount)->toBe('90.000000');
});

it('refuses a stale token before any lifecycle rule runs', function () {
    $invoice = e2Invoice();
    e2Line($invoice, ['lineNo' => 1, 'amount' => '100.000000']);

    expect(fn () => app(CostInvoiceService::class)->confirm($invoice->id, 'i:0'))->toThrow(StaleReconciliationException::class)
        ->and($invoice->fresh()->current_status)->toBe(CostInvoiceEventType::Draft);
});

it('voids and supersedes through append-only events with a state token; a replacement must be a confirmed invoice of the same scope', function () {
    $service = app(CostInvoiceService::class);
    $old = e2ConfirmedInvoice(['service' => '100.000000']);
    $other = e2ConfirmedInvoice(['service' => '50.000000'], ['component' => 'communication', 'counterpartyKey' => 'meta-whatsapp']);
    $draft = e2Invoice();

    expect(e2Rule(fn () => $service->supersede($old->id, $old->stateToken(), $other->id, 'reissued')))->toBe('replacement')
        ->and(e2Rule(fn () => $service->supersede($old->id, $old->stateToken(), $draft->id, 'reissued')))->toBe('replacement')
        ->and(e2Rule(fn () => $service->supersede($old->id, $old->stateToken(), $old->id, 'reissued')))->toBe('replacement')
        ->and(e2Rule(fn () => $service->void($old->id, $old->stateToken(), '')))->toBe('reason_code');

    $replacement = e2ConfirmedInvoice(['service' => '95.000000']);
    $superseded = $service->supersede($old->id, $old->stateToken(), $replacement->id, 'reissued');
    expect($superseded->current_status)->toBe(CostInvoiceEventType::Superseded)->and($superseded->superseded_by_id)->toBe($replacement->id)
        ->and(e2Rule(fn () => $service->void($old->id, $superseded->stateToken(), 'x')))->toBe('lifecycle'); // terminal

    $voided = $service->void($draft->id, $draft->stateToken(), 'duplicate');
    expect($voided->current_status)->toBe(CostInvoiceEventType::Voided)
        ->and(CostInvoiceEvent::query()->where('cost_invoice_id', $draft->id)->orderBy('id')->pluck('event_type')->map(fn ($e) => $e->value)->all())->toBe(['draft', 'voided'])
        ->and(AuditLog::where('action', AuditActions::CostInvoiceTransitioned)->count())->toBe(5); // 3 confirmations + supersede + void
});

it('is atomic with its audit entry: a failing audit store leaves no invoice, line or event', function () {
    e2Provider();
    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => e2Invoice())->toThrow(RuntimeException::class);
    expect(CostInvoice::count())->toBe(0)->and(CostInvoiceEvent::count())->toBe(0)->and(AuditLog::count())->toBe(0);
});
