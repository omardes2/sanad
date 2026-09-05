<?php

declare(strict_types=1);

use App\Enums\AiOperation;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $this->fx = catalogFixture();
    $this->sim = app(RoutingSimulator::class);
});

it('current() explains every candidate and agrees with the router', function () {
    $live = $this->sim->current();

    expect($live->preferredProvider)->toBe('groq')
        ->and($live->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')
        ->and(array_column($live->candidates, 'status'))->toBe(['selected', 'eligible'])
        ->and($live->eligible())->toHaveCount(2);

    // What-if: another preferred provider → different selection, nothing written.
    $whatIf = $this->sim->current(AiOperation::Chat, 'openai');

    expect($whatIf->selectedHandle())->toBe('openai:gpt-4.1-mini')
        ->and($this->sim->current()->selectedHandle())->toBe('groq:llama-3.3-70b-versatile');

    // Unconfigured provider is skipped with a reason.
    config(['ai.providers.openai.api_key' => '']);
    $eval = $this->sim->current(AiOperation::Chat, 'openai');

    expect($eval->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')
        ->and($eval->candidates[0]['reason'])->toBe('unconfigured');
});

it('proposed() applies overrides in memory and never writes', function () {
    ['llama' => $llama, 'groq' => $groq] = $this->fx;

    $after = $this->sim->proposed([], [$llama->id => ['is_enabled' => false]]);

    expect($after->selectedHandle())->toBe('openai:gpt-4.1-mini')
        ->and($llama->fresh()->is_enabled)->toBeTrue()
        ->and($this->sim->proposed([$groq->id => ['is_enabled' => false]])->selectedHandle())->toBe('openai:gpt-4.1-mini')
        ->and($this->sim->proposed([$groq->id => ['is_enabled' => false]], [$this->fx['mini']->id => ['is_enabled' => false]])->hasRoute())->toBeFalse();
});

it('proposed() follows the catalog-source mode: config ignores rows, auto falls back to config when no row would remain', function () {
    ['llama' => $llama, 'mini' => $mini] = $this->fx;

    settings()->set('ai.catalog_source', 'config');
    expect($this->sim->proposed([], [$llama->id => ['is_enabled' => false], $mini->id => ['is_enabled' => false]])->hasRoute())->toBeTrue();

    settings()->set('ai.catalog_source', 'auto');
    // Disabling every database model → auto uses the config catalog → groq still routes.
    $eval = $this->sim->proposed([], [$llama->id => ['is_enabled' => false], $mini->id => ['is_enabled' => false]]);
    expect($eval->hasRoute())->toBeTrue()->and($eval->selectedHandle())->toBe('groq:llama-3.3-70b-versatile');

    settings()->set('ai.catalog_source', 'database');
    expect($this->sim->proposed([], [$llama->id => ['is_enabled' => false], $mini->id => ['is_enabled' => false]])->hasRoute())->toBeFalse();
});

it('proposed() honours a priority override', function () {
    ['groq' => $groq, 'openai' => $openai] = $this->fx;
    config(['ai.provider' => 'gemini']); // no preference among the two → priority decides

    expect($this->sim->proposed()->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')
        ->and($this->sim->proposed([$openai->id => ['priority' => 500]])->selectedHandle())->toBe('openai:gpt-4.1-mini');

    CatalogCache::flush();
    expect(AiProvider::find($openai->id)->priority)->toBe(10)->and(AiModel::count())->toBe(2);
});
