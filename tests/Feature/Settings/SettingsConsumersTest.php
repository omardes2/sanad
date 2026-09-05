<?php

declare(strict_types=1);

use App\Agents\PlaceholderAgentOrchestrator;
use App\Contracts\AgentOrchestrator;
use App\Data\Ai\AiRequest;
use App\Data\Billing\UsageDecision;
use App\Enums\UsageDimension;
use App\Enums\UsageOutcome;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Ai\PromptBuilder;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\UsageLimitResponder;
use App\Services\MessageProcessor;
use App\Services\Settings\SettingsCache;
use App\Support\Ai\ContextRequest;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function promptFor(): AiRequest
{
    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'set-1', 'مرحبا'))->message;

    return app(PromptBuilder::class)->build(new ContextRequest($inbound->user, $inbound->conversation, $inbound));
}

it('persona and temporal context come from the settings and match the previous behaviour by default', function () {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-09-05 12:34:00');
    $request = promptFor();
    $system = $request->messages[0]->content;

    $tz = config('sanad.default_user_timezone');
    $expectedTemporal = sprintf(
        'التاريخ والوقت الآن بتوقيت المستخدم (%s): %s. استخدمه عند فهم كلمات مثل «اليوم» و«غدًا» و«بعد ساعة». وقت النظام يُخزَّن بـUTC.',
        $tz,
        CarbonImmutable::now($tz)->translatedFormat('l، j F Y - H:i'),
    );

    expect($system)->toContain((string) config('ai.persona'))
        ->and($system)->toContain($expectedTemporal);
});

it('a persona / template change is picked up by the very next prompt build without any restart', function () {
    Queue::fake();
    $this->actingAs(userWithRole(Role::SuperAdmin));

    settings()->set('ai.persona', 'أنت «سَنَد»، مساعد مختصر جدًا يجيب بجملة واحدة.');
    settings()->set('prompts.temporal_context', 'الآن: {now}');

    $system = promptFor()->messages[0]->content;

    expect($system)->toContain('مساعد مختصر جدًا')
        ->and($system)->toContain('الآن: ')
        ->and($system)->not->toContain('التاريخ والوقت الآن بتوقيت المستخدم');
});

it('generation parameters and history limit come from the settings', function () {
    Queue::fake();
    $this->actingAs(userWithRole(Role::SuperAdmin));
    settings()->set('ai.temperature', '0.2');
    settings()->set('ai.max_output_tokens', '333');
    settings()->set('ai.timeout', '9');
    settings()->set('ai.history_limit', '1');

    $request = promptFor();

    expect($request->temperature)->toBe(0.2)
        ->and($request->maxOutputTokens)->toBe(333)
        ->and($request->timeout)->toBe(9)
        ->and(count($request->messages))->toBe(2); // system + exactly one history turn
});

it('billing messages and the upgrade link come from the settings', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    settings()->set('billing.limit_reached_message', 'وصلت للحد. للترقية: {upgrade}');
    settings()->set('billing.upgrade_url', 'https://sanad.example/upgrade');
    settings()->set('billing.feature_disabled_message', 'غير متاح في باقتك.');

    $responder = app(UsageLimitResponder::class);

    expect($responder->message(new UsageDecision(UsageOutcome::LimitReached, UsageDimension::AiReply, 'day')))->toBe('وصلت للحد. للترقية: https://sanad.example/upgrade')
        ->and($responder->message(new UsageDecision(UsageOutcome::Disabled, UsageDimension::AiReply)))->toBe('غير متاح في باقتك.');
});

it('auto_trial and default_plan_slug come from the settings', function () {
    $basic = Plan::create(['name' => 'Basic', 'slug' => 'basic', 'price' => 0, 'currency' => 'USD', 'billing_period' => 'monthly', 'trial_days' => 0, 'limits' => [], 'features' => [], 'is_active' => true, 'is_default' => false, 'sort_order' => 1]);
    Plan::create(['name' => 'Free', 'slug' => 'free', 'price' => 0, 'currency' => 'USD', 'billing_period' => 'monthly', 'trial_days' => 0, 'limits' => [], 'features' => [], 'is_active' => true, 'is_default' => true, 'sort_order' => 0]);

    $this->actingAs(userWithRole(Role::SuperAdmin));
    settings()->set('billing.default_plan_slug', 'basic');
    expect(app(SubscriptionService::class)->defaultPlan()?->id)->toBe($basic->id);

    settings()->set('billing.auto_trial', '0');
    $subscriber = User::factory()->create(['is_admin' => false]);
    expect(app(SubscriptionService::class)->assignDefaultIfEnabled($subscriber))->toBeNull();
});

it('ai.enabled from the database switches the orchestrator binding; AI_ENABLED in the environment overrides it', function () {
    config(['ai.enabled' => false, 'ai.overrides.enabled' => null]);
    expect(app(AgentOrchestrator::class))->toBeInstanceOf(PlaceholderAgentOrchestrator::class);

    aiConfigure(['ai.enabled' => false, 'ai.overrides.enabled' => null]);
    AppSetting::create(['key' => 'ai.enabled', 'value' => true]);
    app(SettingsCache::class)->flush();
    expect(app(AgentOrchestrator::class))->not->toBeInstanceOf(PlaceholderAgentOrchestrator::class);

    config(['ai.overrides.enabled' => 'false']); // env kill switch wins over the DB value
    expect(app(AgentOrchestrator::class))->toBeInstanceOf(PlaceholderAgentOrchestrator::class);
});

it('ai.catalog_source from the database drives the resolver; the env override wins', function () {
    config(['ai.catalog_source' => 'auto', 'ai.overrides.catalog_source' => null]);
    expect(app(CatalogSourceResolver::class)->mode())->toBe('auto');

    AppSetting::create(['key' => 'ai.catalog_source', 'value' => 'config']);
    app(SettingsCache::class)->flush();
    expect(app(CatalogSourceResolver::class)->mode())->toBe('config');

    config(['ai.overrides.catalog_source' => 'database']);
    expect(app(CatalogSourceResolver::class)->mode())->toBe('database');
});

it('billing.enforce is displayed from config/env only and UsageEngine still reads config', function () {
    config(['billing.enforce' => false, 'billing.overrides.enforce' => null]);
    $e = settings()->effective('billing.enforce');
    expect($e->value)->toBeFalse()->and($e->source)->toBe('default')->and($e->definition->readOnly)->toBeTrue();

    config(['billing.overrides.enforce' => 'false']);
    expect(settings()->effective('billing.enforce')->source)->toBe('env');
});
