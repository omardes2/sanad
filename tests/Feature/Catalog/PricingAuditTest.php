<?php

declare(strict_types=1);

use App\Data\Billing\PricePublication;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\ModelPrice;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Pricing\PriceBook;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function auditPublication(string $from = '2026-06-01T00:00:00Z'): PricePublication
{
    return new PricePublication(currency: 'USD', inputPerMillion: '1', outputPerMillion: '2', cachedInputPerMillion: null, perRequest: '0', effectiveFrom: CarbonImmutable::parse($from));
}

it('publishes the price and its audit entry in one transaction', function () {
    $this->actingAs(userWithRole(Role::Finance));
    $model = AiModel::factory()->create();

    $price = app(PriceBook::class)->publish($model, auditPublication());
    $log = AuditLog::where('action', AuditActions::AiPricePublished)->firstOrFail();

    expect($log->subject_id)->toBe($price->id)
        ->and($log->actor_ref)->toBe('user:'.auth()->id())
        ->and($log->changes()['price']['to']['input_per_million'])->toBe('1.00000000')
        ->and($log->context()['model'])->toBe($model->handle());
});

it('does NOT publish a price when the audit entry cannot be written', function () {
    $model = AiModel::factory()->create();

    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable'));

    expect(fn () => (new PriceBook($audit))->publish($model, auditPublication()))->toThrow(RuntimeException::class)
        ->and(ModelPrice::where('model_id', $model->id)->count())->toBe(0)
        ->and(AuditLog::where('action', AuditActions::AiPricePublished)->count())->toBe(0);

    // The open period that would have been closed stays open, too.
    $existing = ModelPrice::factory()->for($model, 'model')->create(['effective_from' => CarbonImmutable::parse('2026-01-01T00:00:00Z')]);

    expect(fn () => (new PriceBook($audit))->publish($model, auditPublication('2026-07-01T00:00:00Z')))->toThrow(RuntimeException::class)
        ->and($existing->fresh()->effective_until)->toBeNull();
});
