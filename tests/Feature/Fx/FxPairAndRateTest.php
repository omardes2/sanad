<?php

declare(strict_types=1);

use App\Data\Fx\RecordRateInput;
use App\Exceptions\Fx\StaleFxException;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Models\AuditLog;
use App\Models\FxPair;
use App\Models\FxRate;
use App\Models\FxRateScope;
use App\Services\Audit\AuditLogger;
use App\Services\Fx\FxPairBook;
use App\Services\Fx\FxRateBook;
use App\Support\Audit\AuditActions;
use App\Support\Security\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase E3 — canonical pairs and point-in-time quotes: one pair per two
 * currencies (reverse creation refused), official orientation enforced on
 * every rate, quotes keyed by (pair, rate_date) with append-only revisions
 * under a scope pointer, no validity interval, no lookup helper.
 */
it('creates exactly one canonical pair per two currencies and refuses the reversed pair', function () {
    $pair = app(FxPairBook::class)->create('usd', 'ils');

    expect($pair->pair_key)->toBe('ILS:USD')->and($pair->base_currency)->toBe('USD')->and($pair->quote_currency)->toBe('ILS')
        ->and(fxRule(fn () => app(FxPairBook::class)->create('ILS', 'USD')))->toBe('pair_exists')
        ->and(fxRule(fn () => app(FxPairBook::class)->create('USD', 'ILS')))->toBe('pair_exists')
        ->and(fxRule(fn () => app(FxPairBook::class)->create('USD', 'USD')))->toBe('pair')
        ->and(fxRule(fn () => app(FxPairBook::class)->create('US', 'ILS')))->toBe('base_currency')
        ->and(FxPair::count())->toBe(1)
        ->and(app(FxPairBook::class)->find('ils', 'usd')?->id)->toBe($pair->id)
        ->and(fn () => $pair->forceFill(['base_currency' => 'ILS'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $pair->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(AuditLog::where('action', AuditActions::FxPairCreated)->count())->toBe(1);

    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(fn () => DB::transaction(fn () => DB::table('fx_pairs')->insert(['pair_key' => 'USD:ILS', 'base_currency' => 'USD', 'quote_currency' => 'ILS', 'created_by_ref' => 'console', 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class); // non-canonical key
    }
});

it('records a quote only in the official orientation, only for a real non-future date, with mandatory evidence and a positive scale-12 rate', function () {
    fxPair('USD', 'ILS');
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));

    expect(fxRule(fn () => fxRate(['baseCurrency' => 'ILS', 'quoteCurrency' => 'USD'])))->toBe('orientation') // reverse quote refused, never flipped
        ->and(fxRule(fn () => app(FxRateBook::class)->record(new RecordRateInput('EUR', 'USD', '2026-08-10', '1.1', 'x'))))->toBe('pair') // no pair, no auto-creation
        ->and(fxRule(fn () => fxRate(['rateDate' => '2026-09-07'])))->toBe('rate_date')
        ->and(fxRule(fn () => fxRate(['rateDate' => '2026-02-30'])))->toBe('rate_date')
        ->and(fxRule(fn () => fxRate(['rateDate' => '10/08/2026'])))->toBe('rate_date')
        ->and(fxRule(fn () => fxRate(['rate' => '0'])))->toBe('rate')
        ->and(fxRule(fn () => fxRate(['rate' => '-3.65'])))->toBe('rate')
        ->and(fxRule(fn () => fxRate(['rate' => '3.6500000000001'])))->toBe('rate') // 13 decimals
        ->and(fxRule(fn () => fxRate(['evidenceRef' => ''])))->toBe('evidence_ref')
        ->and(fxRule(fn () => fxRate(['evidenceRef' => 'omar@example.com'])))->toBe('evidence_ref')
        ->and(FxRate::count())->toBe(0);

    $rate = fxRate(['rate' => '3.65', 'rateDate' => '2026-09-06']); // today is fine
    expect((string) $rate->rate)->toBe('3.650000000000')->and($rate->rateDate())->toBe('2026-09-06')->and($rate->source)->toBe('manual')
        ->and($rate->base_currency)->toBe('USD')->and($rate->quote_currency)->toBe('ILS')->and($rate->supersedes_id)->toBeNull()
        ->and($rate->recorded_by_ref)->toBe('console')
        ->and(fn () => $rate->forceFill(['rate' => '4'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $rate->delete())->toThrow(ImmutableFinancialRecordException::class);
});

it('revises a quote append-only under the (pair, date) scope: expected pointer, supersedes_id, pointer move, audit; a stale expectation writes nothing', function () {
    $first = fxRate(['rate' => '3.65']);
    $scope = FxRateScope::query()->firstOrFail();

    expect($scope->current_rate_id)->toBe($first->id)->and($scope->version)->toBe(1)->and($scope->stateToken())->toBe('x:'.$first->id)
        ->and(fn () => fxRate(['rate' => '3.70', 'expectedCurrentRateId' => null]))->toThrow(StaleFxException::class)
        ->and(fn () => fxRate(['rate' => '3.70', 'expectedCurrentRateId' => 999]))->toThrow(StaleFxException::class)
        ->and(FxRate::count())->toBe(1);

    $second = fxRate(['rate' => '3.70', 'expectedCurrentRateId' => $first->id, 'reasonCode' => 'typo']);
    $scope->refresh();

    expect($second->supersedes_id)->toBe($first->id)->and((string) $second->rate)->toBe('3.700000000000')
        ->and($scope->current_rate_id)->toBe($second->id)->and($scope->version)->toBe(2)
        ->and(FxRate::count())->toBe(2)->and((string) $first->fresh()->rate)->toBe('3.650000000000') // history intact
        ->and(FxRateBook::isCurrent($first->fresh()))->toBeFalse()->and(FxRateBook::isCurrent($second))->toBeTrue()
        ->and(AuditLog::where('action', AuditActions::FxRateRecorded)->count())->toBe(2)
        ->and(AuditLog::where('action', AuditActions::FxRateRecorded)->orderByDesc('id')->first()->metadata['changes']['current_rate_id'])->toBe(['from' => $first->id, 'to' => $second->id])
        ->and(fn () => $scope->forceFill(['rate_date' => '2026-01-01'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $scope->delete())->toThrow(ImmutableFinancialRecordException::class);

    // Another date is another scope with its own revisions; quotes never carry over.
    $other = fxRate(['rate' => '3.80', 'rateDate' => '2026-08-11']);
    expect(FxRateScope::count())->toBe(2)->and($other->supersedes_id)->toBeNull()
        ->and(app(FxRateBook::class)->quotesFor('ILS', 'USD', '2026-08-10'))->toHaveCount(1)
        ->and(app(FxRateBook::class)->quotesFor('ILS', 'USD', '2026-08-10')[0]->id)->toBe($second->id)
        ->and(app(FxRateBook::class)->quotesFor('USD', 'ILS', '2026-08-12'))->toBe([]) // no nearest / previous-day quote
        ->and(app(FxRateBook::class)->quotesFor('USD', 'EUR', '2026-08-10'))->toBe([]);
});

it('has no validity interval and no latest / nearest / fallback lookup anywhere in the FX code', function () {
    $columns = Schema::getColumnListing('fx_rates');
    expect($columns)->not->toContain('effective_from', 'effective_until', 'valid_from', 'valid_until');

    foreach (glob(app_path('Services/Fx/*.php')) as $file) {
        $src = strtolower(php_strip_whitespace($file)); // code only — comments explain what is NOT there
        expect(preg_match('/latest|nearest|fallback|previous.?business|orderby.*rate_date.*desc|where\(.rate_date.,\s*.(<|>)/', $src))->toBe(0, basename($file));
    }
});

it('is atomic with the audit entry', function () {
    fxPair();
    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => fxRate())->toThrow(RuntimeException::class);
    expect(FxRate::count())->toBe(0)->and(FxRateScope::count())->toBe(0)->and(AuditLog::where('action', AuditActions::FxRateRecorded)->count())->toBe(0);
});
