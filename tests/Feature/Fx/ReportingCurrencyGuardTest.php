<?php

declare(strict_types=1);

use App\Exceptions\Settings\ReadOnlySettingException;
use App\Exceptions\Settings\TypedConfirmationRequiredException;
use App\Livewire\Dashboard\Finance\Fx as FxPage;
use App\Livewire\Dashboard\Settings as SettingsPage;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Settings\SettingsRepository;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Phase E3 — `finance.reporting_currency` cannot be changed by ANY path other
 * than the FX flow with the new code typed verbatim: the generic settings
 * write and reset refuse the managed key, the Settings page refuses it, and
 * the managed-writer path itself refuses without the typed confirmation —
 * for finance AND super_admin alike. One correct call = one row + one audit.
 */
const RC = SettingsRegistry::REPORTING_CURRENCY;

function rcRows(): int
{
    return AppSetting::query()->where('key', RC)->count();
}

function rcAudits(): int
{
    return AuditLog::where('action', AuditActions::SettingsUpdated)->count() + AuditLog::where('action', AuditActions::FinanceReportingCurrencyChanged)->count();
}

it('refuses the generic settings write and reset for the key, for finance and for super_admin', function () {
    config(['billing.cost_currency' => 'USD']);
    $repo = app(SettingsRepository::class);

    foreach ([userWithRole(Role::Finance), userWithRole(Role::SuperAdmin)] as $user) {
        $this->actingAs($user);
        expect(fn () => $repo->set(RC, 'ILS', 'bypass'))->toThrow(ReadOnlySettingException::class)
            ->and(fn () => $repo->reset(RC, 'bypass'))->toThrow(ReadOnlySettingException::class);
    }

    expect(rcRows())->toBe(0)->and(rcAudits())->toBe(0)->and(app(ReportingCurrencyService::class)->current())->toBe('USD');
});

it('refuses the key on the generic Settings page (read-only there), even for super_admin', function () {
    config(['billing.cost_currency' => 'USD']);
    $admin = userWithRole(Role::SuperAdmin);

    Livewire::actingAs($admin)->test(SettingsPage::class)->assertOk()
        ->set('values.'.str_replace('.', '__', RC), 'ILS')
        ->call('save', RC)->assertSee('للعرض فقط')
        ->call('resetToDefault', RC)->assertSee('للعرض فقط');

    expect(rcRows())->toBe(0)->and(rcAudits())->toBe(0)->and(app(ReportingCurrencyService::class)->current())->toBe('USD');
});

it('refuses the managed-writer path itself without the typed confirmation or with a wrong one — no row, no audit', function () {
    config(['billing.cost_currency' => 'USD']);
    $repo = app(SettingsRepository::class);

    foreach ([userWithRole(Role::Finance), userWithRole(Role::SuperAdmin)] as $user) {
        $this->actingAs($user);
        expect(fn () => $repo->setManaged(RC, 'ILS', 'cutover-style bypass'))->toThrow(TypedConfirmationRequiredException::class)
            ->and(fn () => $repo->setManaged(RC, 'ILS', 'bypass', 'ils'))->toThrow(TypedConfirmationRequiredException::class)
            ->and(fn () => $repo->setManaged(RC, 'ILS', 'bypass', 'USD'))->toThrow(TypedConfirmationRequiredException::class)
            ->and(fn () => $repo->setManaged(RC, 'ILS', 'bypass', ''))->toThrow(TypedConfirmationRequiredException::class)
            ->and(fxRule(fn () => app(ReportingCurrencyService::class)->change('ILS', 'ils')))->toBe('typed_confirmation')
            ->and(fxRule(fn () => app(ReportingCurrencyService::class)->change('ILS', 'ILS ')))->toBe('typed_confirmation');
    }

    expect(rcRows())->toBe(0)->and(rcAudits())->toBe(0)->and(app(ReportingCurrencyService::class)->current())->toBe('USD');
});

it('writes exactly one row and one settings audit plus one FX audit when the code is typed correctly, through the FX flow, for finance and super_admin alike', function () {
    config(['billing.cost_currency' => 'USD']);
    $finance = userWithRole(Role::Finance);

    Livewire::actingAs($finance)->test(FxPage::class)->assertOk()
        ->set('rcCode', 'ILS')->set('rcTyped', 'ils')->call('setReportingCurrency')->assertHasErrors(['reporting_currency'])
        ->set('rcTyped', 'ILS')->call('setReportingCurrency')->assertHasNoErrors()->assertSee('عملة التقرير الآن ILS');

    expect(rcRows())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::SettingsUpdated)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::FinanceReportingCurrencyChanged)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::FinanceReportingCurrencyChanged)->first()->metadata['context']['typed_confirmation'])->toBe('ILS')
        ->and(app(ReportingCurrencyService::class)->current())->toBe('ILS');

    // super_admin: permission alone is not enough — the typed code is still required.
    $this->actingAs(userWithRole(Role::SuperAdmin));
    expect(fxRule(fn () => app(ReportingCurrencyService::class)->change('EUR', 'eur')))->toBe('typed_confirmation')
        ->and(rcRows())->toBe(1)->and(AuditLog::where('action', AuditActions::SettingsUpdated)->count())->toBe(1);
    app(ReportingCurrencyService::class)->change('EUR', 'EUR');
    expect(app(ReportingCurrencyService::class)->current())->toBe('EUR')->and(rcRows())->toBe(1) // same row updated, never a second row
        ->and(AuditLog::where('action', AuditActions::SettingsUpdated)->count())->toBe(2);
});

it('has no other writer of the key in the codebase', function () {
    $writers = [];
    foreach (glob(app_path('**/*.php')) + glob(app_path('**/**/*.php')) + glob(app_path('**/**/**/*.php')) as $file) {
        $src = php_strip_whitespace($file);
        if (preg_match('/setManaged\(\s*SettingsRegistry::REPORTING_CURRENCY|setManaged\(\s*[\'"]finance\.reporting_currency/', $src) === 1) {
            $writers[] = str_replace(app_path().'/', '', $file);
        }
    }

    expect($writers)->toBe(['Services/Fx/ReportingCurrencyService.php']);
});
