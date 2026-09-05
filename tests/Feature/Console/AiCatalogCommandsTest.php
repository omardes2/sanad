<?php

declare(strict_types=1);

use App\Enums\ModelPriceSource;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ModelPrice;
use App\Models\UsageEvent;
use App\Services\Ai\Catalog\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    aiConfigure([
        // Phase C4 Stage A: bootstrap --apply is refused while the source is `auto`.
        'ai.catalog_source' => 'config',
        'ai.provider' => 'groq',
        'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
        'ai.providers.openai.api_key' => '',
        'ai.providers.openai.model' => 'gpt-4.1-mini',
    ]);
});

it('bootstrap is a dry run by default and writes nothing', function () {
    $this->artisan('sanad:ai:bootstrap')
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('groq:llama-3.3-70b-versatile')
        ->expectsOutputToContain('openai:gpt-4.1-mini')
        ->assertSuccessful();

    expect(AiProvider::count())->toBe(0)
        ->and(AiModel::count())->toBe(0);
});

it('bootstrap --apply registers providers and models from config, is idempotent, and never writes prices', function () {
    $this->artisan('sanad:ai:bootstrap', ['--apply' => true])->assertSuccessful();
    $this->artisan('sanad:ai:bootstrap', ['--apply' => true])->assertSuccessful();

    $groq = AiProvider::query()->where('key', 'groq')->firstOrFail();
    $openai = AiProvider::query()->where('key', 'openai')->firstOrFail();

    expect(AiProvider::count())->toBe(2)
        ->and(AiModel::count())->toBe(2)
        ->and(ModelPrice::count())->toBe(0)
        ->and($groq->priority)->toBe(100) // AI_PROVIDER=groq keeps the top priority
        ->and($openai->priority)->toBe(10)
        ->and($groq->is_primary)->toBeFalse()
        ->and($openai->is_primary)->toBeFalse()
        ->and($openai->credentials_ref)->toBe('OPENAI_API_KEY')
        ->and(AiModel::query()->where('external_id', 'llama-3.3-70b-versatile')->exists())->toBeTrue()
        ->and(AiModel::query()->where('external_id', 'gpt-4.1-mini')->exists())->toBeTrue();
});

it('bootstrap accepts explicit --model handles instead of the configured models', function () {
    $this->artisan('sanad:ai:bootstrap', ['--apply' => true, '--model' => ['openai:gpt-4.1', 'groq:llama-3.1-8b-instant']])
        ->assertSuccessful();

    expect(AiModel::query()->pluck('external_id')->sort()->values()->all())->toBe(['gpt-4.1', 'llama-3.1-8b-instant']);
});

it('bootstrap rejects an unknown provider handle', function () {
    $this->artisan('sanad:ai:bootstrap', ['--model' => ['acme:x']])
        ->expectsOutputToContain('Invalid --model')
        ->assertSuccessful();

    expect(AiProvider::count())->toBe(0);
});

it('bootstrap never touches existing rows unless --update-metadata is given', function () {
    $groq = AiProvider::factory()->create(['key' => 'groq', 'name' => 'Custom Name', 'priority' => 7]);
    AiModel::factory()->for($groq, 'provider')->create(['external_id' => 'llama-3.3-70b-versatile', 'name' => 'Custom Model', 'supports_tools' => false]);

    $this->artisan('sanad:ai:bootstrap', ['--apply' => true])->assertSuccessful();

    expect($groq->fresh()->name)->toBe('Custom Name')
        ->and($groq->fresh()->priority)->toBe(7)
        ->and(AiModel::query()->where('external_id', 'llama-3.3-70b-versatile')->value('name'))->toBe('Custom Model');

    $this->artisan('sanad:ai:bootstrap', ['--apply' => true, '--update-metadata' => true])->assertSuccessful();

    expect($groq->fresh()->name)->toBe('Groq')
        ->and($groq->fresh()->priority)->toBe(7) // priority is never rewritten
        ->and(AiModel::query()->where('external_id', 'llama-3.3-70b-versatile')->value('supports_tools'))->toBeTruthy();
});

it('price publishes an explicit period after preview and closes the open one', function () {
    $this->artisan('sanad:ai:bootstrap', ['--apply' => true])->assertSuccessful();

    $this->artisan('sanad:ai:price', [
        '--model' => 'groq:llama-3.3-70b-versatile',
        '--input' => '0.59', '--output' => '0.79',
        '--effective-from' => now()->addMinute()->toIso8601String(),
        '--yes' => true,
    ])->expectsOutputToContain('Example: 1000 in + 300 out')->assertSuccessful();

    $this->artisan('sanad:ai:price', [
        '--model' => 'groq:llama-3.3-70b-versatile',
        '--input' => '0.60', '--output' => '0.80',
        '--effective-from' => now()->addHour()->toIso8601String(),
        '--yes' => true,
    ])->expectsOutputToContain('will be CLOSED')->assertSuccessful();

    $prices = ModelPrice::query()->orderBy('effective_from')->get();

    expect($prices)->toHaveCount(2)
        ->and($prices[0]->effective_until)->not->toBeNull()
        ->and($prices[1]->effective_until)->toBeNull()
        ->and($prices[0]->source)->toBe(ModelPriceSource::Manual)
        ->and((string) $prices[0]->input_per_million)->toBe('0.59000000');
});

it('price refuses a backdated start without --allow-backdate and refuses any overlap even with it', function () {
    $this->artisan('sanad:ai:bootstrap', ['--apply' => true])->assertSuccessful();

    $this->artisan('sanad:ai:price', [
        '--model' => 'groq:llama-3.3-70b-versatile', '--input' => '1', '--output' => '1',
        '--effective-from' => '2026-01-01T00:00:00Z', '--yes' => true,
    ])->expectsOutputToContain('--allow-backdate')->assertFailed();

    expect(ModelPrice::count())->toBe(0);

    $this->artisan('sanad:ai:price', [
        '--model' => 'groq:llama-3.3-70b-versatile', '--input' => '1', '--output' => '1',
        '--effective-from' => '2026-01-01T00:00:00Z', '--allow-backdate' => true, '--yes' => true,
    ])->assertSuccessful();

    // Backdated INSIDE the existing period → overlap → rejected, history untouched.
    $this->artisan('sanad:ai:price', [
        '--model' => 'groq:llama-3.3-70b-versatile', '--input' => '2', '--output' => '2',
        '--effective-from' => '2025-12-01T00:00:00Z', '--allow-backdate' => true, '--yes' => true,
    ])->expectsOutputToContain('overlaps existing price')->assertFailed();

    expect(ModelPrice::count())->toBe(1)
        ->and((string) ModelPrice::query()->first()->input_per_million)->toBe('1.00000000');
});

it('price rejects an unknown model, malformed amounts, and aborts without --yes when declined', function () {
    $this->artisan('sanad:ai:price', ['--model' => 'openai:nope', '--input' => '1', '--output' => '1'])
        ->expectsOutputToContain('Unknown model')->assertFailed();

    $this->artisan('sanad:ai:bootstrap', ['--apply' => true])->assertSuccessful();

    $this->artisan('sanad:ai:price', ['--model' => 'groq:llama-3.3-70b-versatile', '--input' => '0.123456789', '--output' => '1', '--yes' => true])
        ->expectsOutputToContain('Invalid input')->assertFailed();

    $this->artisan('sanad:ai:price', ['--model' => 'groq:llama-3.3-70b-versatile', '--input' => '1', '--output' => '1'])
        ->expectsConfirmation('Publish this price period?', 'no')
        ->expectsOutputToContain('Aborted')
        ->assertFailed();

    expect(ModelPrice::count())->toBe(0);
});

it('catalog shows the active source, the router order, current prices and the unpriced count', function () {
    $this->artisan('sanad:ai:catalog')
        ->expectsOutputToContain('active: config')
        ->expectsOutputToContain('groq:llama-3.3-70b-versatile')
        ->expectsOutputToContain('0 UNPRICED')
        ->assertSuccessful();

    $this->artisan('sanad:ai:bootstrap', ['--apply' => true])->assertSuccessful();
    UsageEvent::factory()->create(['cost_source' => null, 'provider' => 'groq', 'model' => 'llama-3.3-70b-versatile']);

    // Stage A (Phase C4): bootstrapping never switches the runtime by itself —
    // the source stays `config` until an explicit cutover.
    $this->artisan('sanad:ai:catalog')->expectsOutputToContain('active: config')->assertSuccessful();

    config(['ai.catalog_source' => 'database']);
    CatalogCache::flush();

    $this->artisan('sanad:ai:catalog')
        ->expectsOutputToContain('active: database')
        ->expectsOutputToContain('NONE')
        ->expectsOutputToContain('1 UNPRICED')
        ->assertSuccessful();
});
