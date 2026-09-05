<?php

declare(strict_types=1);

use App\Exceptions\Settings\InvalidSettingValueException;
use App\Exceptions\Settings\ReadOnlySettingException;
use App\Exceptions\Settings\UnknownSettingException;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsCache;
use App\Services\Settings\SettingsRepository;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ---- precedence ---------------------------------------------------------------

it('operational: config default when nothing is stored, DB value once stored', function () {
    config(['ai.temperature' => 0.5]);

    expect(settings()->get('ai.temperature'))->toBe(0.5)
        ->and(settings()->effective('ai.temperature')->source)->toBe('default');

    $this->actingAs(userWithRole(Role::SuperAdmin));
    settings()->set('ai.temperature', '0.9');

    expect(settings()->get('ai.temperature'))->toBe(0.9)
        ->and(settings()->effective('ai.temperature')->source)->toBe('db');
});

it('operational: the environment is never consulted — an env-looking config override changes nothing', function () {
    config(['ai.temperature' => 0.5, 'ai.overrides.temperature' => '1.7']); // no such override path in the registry

    expect(settings()->get('ai.temperature'))->toBe(0.5)
        ->and(settings()->effective('ai.temperature')->source)->toBe('default');
});

it('emergency: env override > DB > config default, and the source says which', function () {
    config(['ai.enabled' => false, 'ai.overrides.enabled' => null]);
    expect(settings()->get('ai.enabled'))->toBeFalse()->and(settings()->effective('ai.enabled')->source)->toBe('default');

    $this->actingAs(userWithRole(Role::SuperAdmin));
    settings()->set('ai.enabled', true);
    expect(settings()->get('ai.enabled'))->toBeTrue()->and(settings()->effective('ai.enabled')->source)->toBe('db');

    // The (config-cached) raw env value wins over the stored value.
    config(['ai.overrides.enabled' => 'false']);
    $e = settings()->effective('ai.enabled');
    expect($e->value)->toBeFalse()->and($e->source)->toBe('env')->and($e->envForced())->toBeTrue()->and($e->editable())->toBeFalse();
});

// ---- reading only through config(), never env() --------------------------------

it('resolves defaults from config() so it works under config:cache semantics (no env() at runtime)', function () {
    // Simulate a cached config: only the compiled values exist; no env() lookup can help.
    config(['ai.history_limit' => 7, 'billing.default_plan_slug' => 'from-config']);

    expect(settings()->get('ai.history_limit'))->toBe(7)
        ->and(settings()->get('billing.default_plan_slug'))->toBe('from-config');
});

it('no application code calls env() at runtime', function () {
    $offenders = [];

    foreach ((new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/\benv\s*\(/', (string) file_get_contents($file->getPathname()))) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

// ---- validation / casting -------------------------------------------------------

it('rejects unknown keys and invalid values with clear messages, without writing', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));

    expect(fn () => settings()->set('nope.key', 1))->toThrow(UnknownSettingException::class)
        ->and(fn () => settings()->set('ai.temperature', '3'))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => settings()->set('ai.temperature', 'abc'))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => settings()->set('ai.max_output_tokens', '1.5'))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => settings()->set('ai.timeout', '90'))->toThrow(InvalidSettingValueException::class) // > worker timeout
        ->and(fn () => settings()->set('ai.failure_behavior', 'explode'))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => settings()->set('billing.upgrade_url', 'http://insecure.example'))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => settings()->set('ai.persona', 'short'))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => settings()->set('prompts.temporal_context', 'الوقت {now} و{evil}'))->toThrow(InvalidSettingValueException::class)
        ->and(fn () => settings()->set('prompts.temporal_context', 'بلا العنصر المطلوب'))->toThrow(InvalidSettingValueException::class);

    expect(AppSetting::count())->toBe(0)
        ->and(AuditLog::whereIn('action', [AuditActions::SettingsUpdated, AuditActions::SettingsReset])->count())->toBe(0);
});

it('casts form strings to native types and stores them as JSON', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));

    settings()->set('billing.auto_trial', '0');
    settings()->set('ai.max_output_tokens', '800');
    settings()->set('billing.upgrade_url', '');

    expect(settings()->get('billing.auto_trial'))->toBeFalse()
        ->and(settings()->get('ai.max_output_tokens'))->toBe(800)
        ->and(settings()->get('billing.upgrade_url'))->toBeNull()
        ->and(AppSetting::where('key', 'billing.auto_trial')->value('value'))->toBe(false);
});

// ---- authorization (server-side, per setting) ----------------------------------

it('operations may set generation, persona and billing messages but not billing/subscription, guardrail or emergency keys', function () {
    $this->actingAs(userWithRole(Role::Operations));

    settings()->set('ai.temperature', '0.7');
    settings()->set('ai.persona', str_repeat('شخصية سَنَد ', 5));
    settings()->set('billing.feature_disabled_message', 'هذه الميزة غير متاحة الآن.');

    expect(fn () => settings()->set('billing.auto_trial', false))->toThrow(AuthorizationException::class)
        ->and(fn () => settings()->set('billing.default_plan_slug', 'plus'))->toThrow(AuthorizationException::class)
        ->and(fn () => settings()->set('ai.guardrails.max_cost_per_request', '0.01'))->toThrow(AuthorizationException::class)
        ->and(fn () => settings()->set('ai.enabled', true))->toThrow(AuthorizationException::class)
        ->and(fn () => settings()->reset('billing.auto_trial'))->toThrow(AuthorizationException::class)
        ->and(AppSetting::count())->toBe(3);
});

it('finance and support cannot write any setting; a legacy is_admin without a role cannot either', function () {
    foreach ([userWithRole(Role::Finance), userWithRole(Role::Support), User::factory()->create(['is_admin' => true])] as $user) {
        $this->actingAs($user);
        expect(fn () => settings()->set('ai.temperature', '0.7'))->toThrow(AuthorizationException::class);
    }

    expect(AppSetting::count())->toBe(0);
});

it('refuses unauthenticated web writes but allows console (audited as console)', function () {
    // Console: allowed (artisan operators).
    settings()->set('ai.temperature', '0.6');
    expect(AuditLog::latest('id')->first()->actor)->toBe('console');
});

it('billing.enforce is read-only: neither set nor reset, from anyone', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));

    expect(fn () => settings()->set('billing.enforce', true))->toThrow(ReadOnlySettingException::class)
        ->and(fn () => settings()->reset('billing.enforce'))->toThrow(ReadOnlySettingException::class)
        ->and(settings()->effective('billing.enforce')->editable())->toBeFalse()
        ->and(AppSetting::count())->toBe(0);
});

// ---- audit atomicity ------------------------------------------------------------

it('set() writes the row and the audit entry in one transaction: an audit failure rolls the setting back', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));

    $failing = Mockery::mock(AuditLogger::class);
    $failing->shouldReceive('record')->once()->andThrow(new RuntimeException('audit store down'));
    app()->instance(AuditLogger::class, $failing);

    expect(fn () => app(SettingsRepository::class)->set('ai.temperature', '0.9'))->toThrow(RuntimeException::class);

    app()->forgetInstance(AuditLogger::class);

    expect(AppSetting::count())->toBe(0)
        ->and(settings()->get('ai.temperature'))->toBe(0.5)
        ->and(settings()->effective('ai.temperature')->source)->toBe('default');
});

it('set() and reset() record before/after and the new source', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $this->actingAs($admin);

    settings()->set('ai.temperature', '0.9', 'tuning');
    $set = AuditLog::where('action', AuditActions::SettingsUpdated)->latest('id')->firstOrFail();

    expect($set->changes()['ai.temperature'])->toBe(['from' => 0.5, 'to' => 0.9])
        ->and($set->context()['source_before'])->toBe('default')
        ->and($set->context()['source_after'])->toBe('db')
        ->and($set->context()['reason'])->toBe('tuning')
        ->and($set->actor_ref)->toBe('user:'.$admin->id)
        ->and($set->subject_type)->toBe((new AppSetting)->getMorphClass());

    settings()->reset('ai.temperature');
    $reset = AuditLog::where('action', AuditActions::SettingsReset)->latest('id')->firstOrFail();

    expect($reset->changes()['ai.temperature'])->toBe(['from' => 0.9, 'to' => 0.5])
        ->and($reset->context()['source_before'])->toBe('db')
        ->and($reset->context()['source_after'])->toBe('default')
        ->and(AppSetting::count())->toBe(0)
        ->and(settings()->effective('ai.temperature')->source)->toBe('default');

    // Resetting an unstored key is a no-op with no audit noise.
    settings()->reset('ai.temperature');
    expect(AuditLog::where('action', AuditActions::SettingsReset)->count())->toBe(1);
});

// ---- invalid stored value -------------------------------------------------------

it('an invalid stored value (registry changed later) falls back to the default, logs a warning without the value, and is flagged', function () {
    Log::spy();
    AppSetting::create(['key' => 'ai.temperature', 'value' => 9.5]); // out of range today
    AppSetting::create(['key' => 'ai.failure_behavior', 'value' => 'legacy-mode']);

    $t = settings()->effective('ai.temperature');
    $f = settings()->effective('ai.failure_behavior');

    expect($t->value)->toBe(0.5)->and($t->source)->toBe('default')->and($t->invalid)->toBeTrue()->and($t->stored)->toBeTrue()->and($t->invalidReason)->not->toBeEmpty()
        ->and($f->value)->toBe('retry')->and($f->invalid)->toBeTrue();

    Log::shouldHaveReceived('warning')->with('sanad.settings.invalid_stored_value', ['key' => 'ai.temperature'])->atLeast()->once();
    Log::shouldNotHaveReceived('warning', fn (string $m, array $c) => in_array(9.5, $c, true) || in_array('legacy-mode', $c, true));

    // Correcting it clears the flag.
    $this->actingAs(userWithRole(Role::SuperAdmin));
    settings()->set('ai.temperature', '0.8');
    expect(settings()->effective('ai.temperature')->invalid)->toBeFalse();
});

// ---- cache / fallback -----------------------------------------------------------

it('caches the stored map and invalidates it on write, so a change is visible immediately', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));

    expect(settings()->get('ai.history_limit'))->toBe(10);
    settings()->set('ai.history_limit', '3');
    expect(settings()->get('ai.history_limit'))->toBe(3);

    // A direct DB write (outside the repository) is invisible until the TTL/version changes…
    AppSetting::where('key', 'ai.history_limit')->update(['value' => json_encode(4)]);
    expect(settings()->get('ai.history_limit'))->toBe(3);
    // …and visible after a flush (what any repository write does).
    app(SettingsCache::class)->flush();
    expect(settings()->get('ai.history_limit'))->toBe(4);
});

it('keeps working when the cache store throws (reads the database directly)', function () {
    AppSetting::create(['key' => 'ai.history_limit', 'value' => 6]);

    Cache::shouldReceive('remember')->andThrow(new RuntimeException('redis down'));
    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
    Cache::shouldReceive('forever')->andThrow(new RuntimeException('redis down'));

    expect(settings()->get('ai.history_limit'))->toBe(6);
});

it('falls back to config defaults when the app_settings table does not exist yet', function () {
    Schema::drop('app_settings');

    expect(settings()->get('ai.temperature'))->toBe(0.5)
        ->and(settings()->effective('ai.temperature')->source)->toBe('default');
});
