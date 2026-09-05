<?php

declare(strict_types=1);

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ModelPrice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores no credential in the AI catalog tables (keys stay in the environment until Phase C)', function () {
    foreach (['ai_providers', 'ai_models', 'model_prices'] as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            expect(strtolower($column))
                ->not->toContain('api_key')
                ->not->toContain('secret')
                ->not->toContain('access_token')
                ->not->toContain('password');
        }
    }

    // The only credential-related column is a REFERENCE (an env variable name).
    expect(Schema::hasColumn('ai_providers', 'credentials_ref'))->toBeTrue();
});

// Each deliberately violating statement runs in its own savepoint
// (DB::transaction): on PostgreSQL a failed statement aborts the enclosing
// (RefreshDatabase) transaction, so nothing after it could run otherwise.
it('enforces at most one primary provider and one open price per model at the database level', function () {
    AiProvider::factory()->create(['key' => 'a', 'is_primary' => true]);
    AiProvider::factory()->create(['key' => 'b', 'is_primary' => false]); // fine: false is not unique-constrained

    expect(fn () => DB::transaction(fn () => AiProvider::factory()->create(['key' => 'c', 'is_primary' => true])))
        ->toThrow(QueryException::class);

    $model = AiModel::factory()->create();
    ModelPrice::factory()->for($model, 'model')->create(['effective_until' => null]);
    ModelPrice::factory()->for($model, 'model')->create(['effective_from' => now()->subYear(), 'effective_until' => now()->subMonth()]); // closed: fine

    expect(fn () => DB::transaction(fn () => ModelPrice::factory()->for($model, 'model')->create(['effective_until' => null])))
        ->toThrow(QueryException::class);

    expect(AiProvider::where('is_primary', true)->count())->toBe(1)
        ->and(ModelPrice::where('model_id', $model->id)->whereNull('effective_until')->count())->toBe(1);
});

it('a model cannot be its own fallback (PostgreSQL check constraint) and a provider with models cannot be deleted', function () {
    $model = AiModel::factory()->create();

    if (DB::getDriverName() === 'pgsql') {
        expect(fn () => DB::transaction(fn () => $model->forceFill(['fallback_model_id' => $model->id])->save()))
            ->toThrow(QueryException::class);
    }

    expect(fn () => DB::transaction(fn () => $model->provider->delete()))->toThrow(QueryException::class);
});
