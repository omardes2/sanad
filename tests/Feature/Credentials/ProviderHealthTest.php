<?php

declare(strict_types=1);

use App\Enums\CostSource;
use App\Enums\HealthCheckKind;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckTrigger;
use App\Exceptions\Credentials\CredentialLifecycleException;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\ModelPrice;
use App\Models\ProviderHealthCheck;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Providers\Ai\OpenAICompatibleChatProvider;
use App\Services\Ai\AiManager;
use App\Services\Ai\Health\ProviderHealthService;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $this->fx = c3Catalog();
    $this->health = app(ProviderHealthService::class);
});

it('connectivity needs no credential and treats any HTTP answer as reachable; a connection failure is failed', function () {
    Http::fake(['api.groq.com/*' => Http::response('', 401)]);
    $check = $this->health->run($this->fx['groq'], HealthCheckKind::Connectivity);

    expect($check->status)->toBe(HealthCheckStatus::Ok)->and($check->http_status)->toBe(401)->and($check->credential_source->value)->toBe('env');
    Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));

    Http::fake(['api.groq.com/*' => fn () => throw new ConnectionException('refused')]);
    $check = $this->health->run($this->fx['groq'], HealthCheckKind::Connectivity);
    expect($check->status)->toBe(HealthCheckStatus::Failed)->and($check->error_code)->toBe('connection')->and($check->error_class)->toBe(ConnectionException::class);
});

it('auth maps 200 → ok (+catalog model known/unknown), 401 → failed unauthorized, 429/5xx → degraded, timeout → failed', function (int|string $answer, string $status, ?string $code) {
    Http::fake(['api.groq.com/*' => is_int($answer) ? Http::response(['data' => [['id' => 'other-model']]], $answer) : fn () => throw new ConnectionException('timeout')]);

    $check = $this->health->run($this->fx['groq'], HealthCheckKind::Auth);

    expect($check->status->value)->toBe($status)->and($check->error_code)->toBe($code);

    if ($answer === 200) {
        expect($check->details['catalog_models_unknown'])->toBe(['llama-3.3-70b-versatile'])->and($check->details['models_listed'])->toBe(1);
    }

    if (is_int($answer)) {
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer test-groq-key'));
    }
})->with([
    'ok' => [200, 'ok', null],
    'unauthorized' => [401, 'failed', 'unauthorized'],
    'rate limited' => [429, 'degraded', 'rate_limited'],
    'server error' => [503, 'degraded', 'server_error'],
    'timeout' => ['timeout', 'failed', 'connection'],
]);

it('an adapter without a declared non-billable auth probe gets auth recorded as skipped/unsupported — connectivity never counts as auth', function () {
    app(AiManager::class)->extend('compat', fn ($c, array $config) => new class('compat', $config) extends OpenAICompatibleChatProvider {});
    config(['ai.providers.compat' => ['base_url' => 'https://compat.example.com/v1', 'api_key' => 'compat-key', 'model' => 'm']]);
    $compat = AiProvider::factory()->create(['key' => 'compat', 'driver' => 'compat']);
    Http::fake(['compat.example.com/*' => Http::response(['data' => []], 200)]);

    $auth = $this->health->run($compat, HealthCheckKind::Auth);
    $conn = $this->health->run($compat, HealthCheckKind::Connectivity);

    expect($auth->status)->toBe(HealthCheckStatus::Skipped)->and($auth->error_code)->toBe('unsupported')
        ->and($conn->status)->toBe(HealthCheckStatus::Ok);
    Http::assertSentCount(1); // the skipped auth probe made no request

    // A provider with no adapter at all: skipped/no_adapter.
    $ghost = AiProvider::factory()->create(['key' => 'gemini', 'driver' => 'gemini']);
    expect($this->health->run($ghost, HealthCheckKind::Auth)->error_code)->toBe('no_adapter');
});

it('billable inference is manual + typed confirmation only, and its consumption lands in the ledger as a system-attributed health_check row linked from the check', function () {
    ModelPrice::factory()->for($this->fx['llama'], 'model')->create(['input_per_million' => '1', 'output_per_million' => '2']);
    Http::fake(['api.groq.com/*' => Http::response(['model' => 'llama-3.3-70b-versatile', 'choices' => [['message' => ['content' => 'pong']]], 'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 1]], 200)]);

    expect(fn () => $this->health->run($this->fx['groq'], HealthCheckKind::Inference))->toThrow(CredentialLifecycleException::class, 'COST')
        ->and(fn () => $this->health->run($this->fx['groq'], HealthCheckKind::Inference, HealthCheckTrigger::Scheduled, confirmation: 'COST'))->toThrow(CredentialLifecycleException::class, 'يدوي')
        ->and(UsageEvent::count())->toBe(0)
        ->and(ProviderHealthCheck::count())->toBe(0);

    $check = $this->health->run($this->fx['groq'], HealthCheckKind::Inference, confirmation: 'COST');
    $event = UsageEvent::findOrFail($check->usage_event_id);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/chat/completions') && $r['max_tokens'] === 1);
    expect($check->status)->toBe(HealthCheckStatus::Ok)
        ->and($check->cost_incurred)->toBeTrue()
        ->and($event->subscriber_id)->toBeNull()
        ->and($event->user_id)->toBeNull()
        ->and($event->subscription_id)->toBeNull()
        ->and($event->operation)->toBe('health_check')
        ->and($event->channel)->toBe('admin')
        ->and($event->provider)->toBe('groq')
        ->and($event->input_units)->toBe(4)->and($event->output_units)->toBe(1)
        ->and($event->cost_source)->toBe(CostSource::ModelPrice)
        ->and((float) $event->total_cost)->toBeGreaterThan(0)
        ->and($event->metadata['attribution'])->toBe('system')
        ->and(UsageCounter::count())->toBe(0); // no quota charge

    $audit = AuditLog::where('action', AuditActions::AiProviderHealthChecked)->latest('id')->firstOrFail();
    expect($audit->context())->toMatchArray(['kind' => 'inference', 'cost_incurred' => true, 'usage_event_id' => $event->id]);
});

it('scheduled run is gated by ai.health.scheduled, runs only the auth probe, records skipped for adapters without one, and never audits', function () {
    Http::fake(['api.groq.com/*' => Http::response(['data' => []], 200), 'api.openai.com/*' => Http::response(['data' => []], 200)]);

    $this->artisan('sanad:ai:health:run')->expectsOutputToContain('disabled')->assertSuccessful();
    expect(ProviderHealthCheck::count())->toBe(0);
    Http::assertNothingSent();

    config(['ai.health.scheduled' => true]);
    $this->artisan('sanad:ai:health:run')->assertSuccessful();

    $rows = ProviderHealthCheck::all();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('kind')->unique()->all())->toBe([HealthCheckKind::Auth])
        ->and($rows->pluck('trigger')->unique()->all())->toBe([HealthCheckTrigger::Scheduled])
        ->and(AuditLog::where('action', AuditActions::AiProviderHealthChecked)->count())->toBe(0);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'chat/completions'));

    // The scheduler wiring: the health job exists, is gated, and never names inference.
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);
    expect($events->filter(fn ($c) => str_contains((string) $c, 'sanad:ai:health:run')))->toHaveCount(1)
        ->and($events->filter(fn ($c) => str_contains((string) $c, 'sanad:ai:health:prune')))->toHaveCount(1)
        ->and($events->filter(fn ($c) => str_contains((string) $c, 'inference')))->toHaveCount(0);
});

it('throttles manual checks per provider, prunes old history, and enforces ai.credentials.test', function () {
    Http::fake(['api.groq.com/*' => Http::response('', 401)]);

    for ($i = 0; $i < 6; $i++) {
        $this->health->run($this->fx['groq'], HealthCheckKind::Connectivity);
    }

    expect(fn () => $this->health->run($this->fx['groq'], HealthCheckKind::Connectivity))->toThrow(CredentialLifecycleException::class, 'حد')
        ->and(ProviderHealthCheck::count())->toBe(6);

    ProviderHealthCheck::query()->update(['checked_at' => now()->subDays(120)]);
    $this->artisan('sanad:ai:health:prune')->assertSuccessful();
    expect(ProviderHealthCheck::count())->toBe(0);

    $this->actingAs(userWithRole(Role::Finance));
    expect(fn () => app(ProviderHealthService::class)->run($this->fx['groq'], HealthCheckKind::Connectivity))->toThrow(AuthorizationException::class);
});
