<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Persona;
use App\Livewire\Dashboard\Settings;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('routes: guest → login; no role / legacy admin / finance / support → 403; super_admin and operations → 200', function () {
    foreach (['dashboard.settings', 'dashboard.persona'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    rbacSync();
    $noRole = User::factory()->create(['is_admin' => false]);
    $legacy = User::factory()->create(['is_admin' => true]);

    foreach (['dashboard.settings', 'dashboard.persona'] as $route) {
        $this->actingAs($noRole)->get(route($route))->assertForbidden();
        $this->actingAs($legacy)->get(route($route))->assertForbidden();
        $this->actingAs(userWithRole(Role::Finance))->get(route($route))->assertForbidden();
        $this->actingAs(userWithRole(Role::Support))->get(route($route))->assertForbidden();
        $this->actingAs(userWithRole(Role::Operations))->get(route($route))->assertOk();
        $this->actingAs(userWithRole(Role::SuperAdmin))->get(route($route))->assertOk();
    }
});

it('shows billing.enforce read-only with its effective value and source, and env-forced keys without an editor', function () {
    config(['billing.enforce' => false, 'billing.overrides.enforce' => null, 'ai.overrides.enabled' => 'true']);

    Livewire::actingAs(userWithRole(Role::SuperAdmin))
        ->test(Settings::class)
        ->assertOk()
        ->assertSee('billing.enforce')
        ->assertSee('للعرض فقط')
        ->assertSee('مُجبَر من البيئة (AI_ENABLED)')
        ->assertSee('الافتراضي');
});

it('operations can save a generation setting but is forbidden on a billing setting even if it calls save directly', function () {
    $ops = userWithRole(Role::Operations);

    Livewire::actingAs($ops)
        ->test(Settings::class)
        ->set('values.ai__temperature', '0.3')
        ->call('save', 'ai.temperature')
        ->assertHasNoErrors()
        ->assertSee('تم حفظ');

    expect(settings()->get('ai.temperature'))->toBe(0.3)
        ->and(AuditLog::where('action', AuditActions::SettingsUpdated)->count())->toBe(1);

    Livewire::actingAs($ops)
        ->test(Settings::class)
        ->set('values.billing__auto_trial', '0')
        ->call('save', 'billing.auto_trial')
        ->assertForbidden();

    expect(AppSetting::where('key', 'billing.auto_trial')->exists())->toBeFalse();
});

it('shows a validation message inline and never persists an invalid value', function () {
    Livewire::actingAs(userWithRole(Role::SuperAdmin))
        ->test(Settings::class)
        ->set('values.ai__timeout', '120')
        ->call('save', 'ai.timeout')
        ->assertSee('45');

    expect(AppSetting::count())->toBe(0);
});

it('flags an invalid stored value on the page and lets an admin reset it', function () {
    AppSetting::create(['key' => 'ai.max_output_tokens', 'value' => 999999]);

    Livewire::actingAs(userWithRole(Role::SuperAdmin))
        ->test(Settings::class)
        ->assertSee('القيمة المخزَّنة غير صالحة')
        ->call('resetToDefault', 'ai.max_output_tokens')
        ->assertDontSee('القيمة المخزَّنة غير صالحة');

    expect(AppSetting::count())->toBe(0)
        ->and(AuditLog::where('action', AuditActions::SettingsReset)->count())->toBe(1);
});

it('persona page saves persona and template with audit, validates placeholders, and previews', function () {
    Livewire::actingAs(userWithRole(Role::SuperAdmin))
        ->test(Persona::class)
        ->assertOk()
        ->set('persona', 'أنت «سَنَد»، مساعد ودود ومختصر يجيب بالعربية.')
        ->call('savePersona')
        ->assertSee('تم حفظ الشخصية')
        ->set('temporal', 'الآن {now} في {evil}')
        ->call('saveTemporal')
        ->assertSee('غير معروف')
        ->set('temporal', 'الآن {now} بتوقيت {timezone}')
        ->call('saveTemporal')
        ->assertSee('تم حفظ قالب سياق الوقت')
        ->assertSee('الآن ');

    expect(settings()->effective('ai.persona')->source)->toBe('db')
        ->and(settings()->get('prompts.temporal_context'))->toBe('الآن {now} بتوقيت {timezone}')
        ->and(AuditLog::where('action', AuditActions::SettingsUpdated)->count())->toBe(2);
});

it('persona page is refused inside the component to accounts without persona.manage', function () {
    rbacSync();
    Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test(Persona::class)->assertForbidden();
    Livewire::actingAs(userWithRole(Role::Finance))->test(Persona::class)->assertForbidden();
});
