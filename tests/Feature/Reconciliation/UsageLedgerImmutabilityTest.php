<?php

declare(strict_types=1);

use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Models\UsageEvent;
use App\Services\Reconciliation\CostReconciliationService;
use App\Services\Reconciliation\ReconciledCostQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E2 — the calculated ledger is immutable: cost / pricing facts cannot
 * be updated and rows cannot be deleted through the model, and the E2
 * services never issue an UPDATE or DELETE against usage_events (checked on
 * the wire and in the source), so every difference lives next to the ledger.
 */
it('refuses model updates of any cost / pricing fact and refuses deletes, while non-financial columns stay editable', function () {
    $row = financeRow(['total_cost' => '1.250000', 'provider_cost' => '1.250000']);

    foreach (['cost' => '9', 'provider_cost' => '9', 'communication_cost' => '9', 'external_cost' => '9', 'total_cost' => '9', 'currency' => 'EUR', 'cost_source' => 'none', 'model_price_id' => 42, 'ai_model_id' => 42, 'occurred_at' => now(), 'provider' => 'openai', 'model' => 'x', 'pricing_snapshot' => ['x' => 1]] as $attribute => $value) {
        expect(fn () => $row->fresh()->forceFill([$attribute => $value])->save())->toThrow(ImmutableFinancialRecordException::class, $attribute);
    }

    expect(fn () => $row->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and((string) $row->fresh()->total_cost)->toBe('1.250000')
        ->and(UsageEvent::count())->toBe(1);

    // A non-financial reference may still be set (e.g. a job link).
    $row->fresh()->forceFill(['job_ref' => 'job:1'])->save();
    expect($row->fresh()->job_ref)->toBe('job:1');
});

it('never issues UPDATE or DELETE against usage_events during a full reconciliation flow (wire-level check)', function () {
    financeRow(['total_cost' => '1.000000', 'provider_cost' => '1.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-10', 'UTC')]);
    $inv = e2ConfirmedInvoice(['service' => '5.000000', 'credit' => '-1.000000']);
    [$service, $credit] = $inv->lines()->orderBy('line_no')->get()->all();

    $offending = [];
    DB::listen(function (QueryExecuted $q) use (&$offending): void {
        if (preg_match('/^\s*(update|delete)\b/i', $q->sql) === 1 && str_contains(strtolower($q->sql), 'usage_events')) {
            $offending[] = $q->sql;
        }
    });

    $rec = e2Reconcile([[$service->id, '5.000000'], [$credit->id, '-1.000000']]);
    app(CostReconciliationService::class)->adjust($rec->id, '-0.250000', 'credit_note', 'cn:1', e2Key());
    e2Reconcile([], ['expectedCurrentReconciliationId' => $rec->id, 'source' => 'manual_evidenced', 'reconciledAmount' => '4.000000', 'reasonCode' => 'restated', 'evidenceRef' => 'stmt']);
    app(ReconciledCostQuery::class)->summarise('2026-08', '2026-08');

    expect($offending)->toBe([])
        ->and((string) UsageEvent::query()->firstOrFail()->total_cost)->toBe('1.000000');
});

it('has no usage_events write in the E2 service sources', function () {
    foreach (glob(app_path('Services/Reconciliation/*.php')) as $file) {
        $src = (string) file_get_contents($file);
        expect(preg_match('/usage_events[^;]*->(update|delete|truncate)\(|UsageEvent::[^;]*->(update|delete|truncate|forceDelete)\(|->(update|delete)\(\[[^\]]*cost/i', $src))->toBe(0, basename($file));
    }
});
