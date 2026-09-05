<?php

declare(strict_types=1);

use App\Enums\SettingPrecedence;
use App\Support\Rbac\Permission;
use App\Support\Settings\SettingsRegistry;

it('registers exactly the C1 + C3 keys with a default, a group, a permission and a precedence each', function () {
    $registry = app(SettingsRegistry::class);
    $keys = array_keys($registry->all());

    expect($keys)->toBe([
        'ai.persona', 'prompts.temporal_context',
        'ai.temperature', 'ai.max_output_tokens', 'ai.history_limit', 'ai.timeout',
        'ai.failure_behavior', 'ai.fallback_message',
        'billing.limit_reached_message', 'billing.feature_disabled_message', 'billing.upgrade_url',
        'billing.auto_trial', 'billing.default_plan_slug',
        'ai.guardrails.max_cost_per_request', 'ai.guardrails.estimate_input_tokens', 'ai.guardrails.estimate_output_tokens',
        'ai.health.scheduled',
        'ai.credentials_mode', 'ai.enabled', 'ai.catalog_source', 'billing.enforce',
    ]);

    foreach ($registry->all() as $definition) {
        expect(config()->has($definition->defaultConfigPath))->toBeTrue("{$definition->key} default path")
            ->and($definition->group)->toBeIn(array_keys(SettingsRegistry::groupLabels()))
            ->and($definition->sensitive)->toBeFalse(); // C1 registers no secret
    }
});

it('gives operational settings DB > config precedence with NO env override path, and emergency ones an override path', function () {
    foreach (app(SettingsRegistry::class)->all() as $definition) {
        if ($definition->precedence === SettingPrecedence::Operational) {
            expect($definition->overrideConfigPath)->toBeNull($definition->key)
                ->and($definition->envKey)->toBeNull($definition->key);
        } else {
            expect($definition->overrideConfigPath)->not->toBeNull($definition->key)
                ->and($definition->envKey)->not->toBeNull($definition->key);
        }
    }

    expect(app(SettingsRegistry::class)->require('ai.enabled')->precedence)->toBe(SettingPrecedence::Emergency)
        ->and(app(SettingsRegistry::class)->require('ai.catalog_source')->precedence)->toBe(SettingPrecedence::Emergency)
        ->and(app(SettingsRegistry::class)->require('billing.enforce')->precedence)->toBe(SettingPrecedence::Emergency)
        ->and(app(SettingsRegistry::class)->require('ai.persona')->precedence)->toBe(SettingPrecedence::Operational);
});

it('assigns per-setting permissions: billing/guardrails and emergency keys are not covered by settings.manage', function () {
    $registry = app(SettingsRegistry::class);

    expect($registry->require('ai.temperature')->permission)->toBe(Permission::SettingsManage)
        ->and($registry->require('ai.persona')->permission)->toBe(Permission::PersonaManage)
        ->and($registry->require('prompts.temporal_context')->permission)->toBe(Permission::PersonaManage)
        ->and($registry->require('billing.auto_trial')->permission)->toBe(Permission::SettingsManageBilling)
        ->and($registry->require('billing.default_plan_slug')->permission)->toBe(Permission::SettingsManageBilling)
        ->and($registry->require('ai.guardrails.max_cost_per_request')->permission)->toBe(Permission::SettingsManageBilling)
        ->and($registry->require('ai.enabled')->permission)->toBe(Permission::SettingsManageEmergency)
        ->and($registry->require('billing.enforce')->readOnly)->toBeTrue()
        ->and($registry->require('ai.enabled')->readOnly)->toBeFalse();
});

it('the temporal prompt default equals the previously hard-coded text with placeholders', function () {
    expect(config('ai.prompts.temporal_context'))
        ->toBe('التاريخ والوقت الآن بتوقيت المستخدم ({timezone}): {now}. استخدمه عند فهم كلمات مثل «اليوم» و«غدًا» و«بعد ساعة». وقت النظام يُخزَّن بـUTC.');
});
