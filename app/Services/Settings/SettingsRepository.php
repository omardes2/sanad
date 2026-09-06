<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Data\Settings\EffectiveSetting;
use App\Enums\SettingPrecedence;
use App\Enums\SettingType;
use App\Exceptions\Settings\InvalidSettingValueException;
use App\Exceptions\Settings\ReadOnlySettingException;
use App\Exceptions\Settings\TypedConfirmationRequiredException;
use App\Models\AppSetting;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Settings\PromptTemplate;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * The runtime source of every registered setting (Phase C1) and the ONLY
 * writer of app_settings.
 *
 * Reading — effective(key): per-key precedence from the registry.
 *   Operational: DB row → config default. The environment is never consulted.
 *   Emergency:   env override (captured in config at config time) → DB row →
 *                config default.
 *   A stored value that no longer passes the registry's validation (the
 *   registry changed after it was saved) is ignored, logged as a warning
 *   (key only, never the value) and flagged `invalid` so the admin page can
 *   ask for a correction — the message pipeline keeps running on the default.
 *
 * Writing — set()/reset(): the registry's permission is enforced server-side
 * for the authenticated user (console runs are allowed and audited as
 * console), the value is cast + validated (templates: placeholder allowlist),
 * and the row and its audit entry are written in ONE transaction, so an
 * audit failure rolls the setting change back.
 *
 * The environment is never read here at runtime: everything comes from
 * config() (config:cache safe) or the database.
 */
class SettingsRepository
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly AuditLogger $audit,
        private readonly SettingsCache $cache,
    ) {}

    /**
     * Drop the stored-values cache so the next effective() re-reads the
     * database (used under a row lock by the cutover service).
     */
    public function cacheFlush(): void
    {
        $this->cache->flush();
    }

    public function registry(): SettingsRegistry
    {
        return $this->registry;
    }

    public function get(string $key): mixed
    {
        return $this->effective($key)->value;
    }

    public function effective(string $key): EffectiveSetting
    {
        return $this->resolve($this->registry->require($key), $this->storedValues());
    }

    /**
     * @return array<string, EffectiveSetting> keyed by setting key, registry order
     */
    public function allEffective(): array
    {
        $stored = $this->storedValues();
        $out = [];

        foreach ($this->registry->all() as $key => $definition) {
            $out[$key] = $this->resolve($definition, $stored);
        }

        return $out;
    }

    /**
     * @throws AuthorizationException|ReadOnlySettingException|InvalidSettingValueException
     */
    public function set(string $key, mixed $value, ?string $reason = null): EffectiveSetting
    {
        return $this->write($key, $value, $reason, false);
    }

    /**
     * Write a MANAGED setting on behalf of its dedicated writer (Phase C4:
     * RoutingCutover). Same validation, transaction and audit as set(); only
     * the "managed" refusal is lifted. Never call this from a page.
     */
    public function setManaged(string $key, mixed $value, ?string $reason = null, ?string $typedConfirmation = null): EffectiveSetting
    {
        return $this->write($key, $value, $reason, true, $typedConfirmation);
    }

    /**
     * @throws AuthorizationException|ReadOnlySettingException|InvalidSettingValueException|TypedConfirmationRequiredException
     */
    private function write(string $key, mixed $value, ?string $reason, bool $viaManagedWriter, ?string $typedConfirmation = null): EffectiveSetting
    {
        $definition = $this->registry->require($key);
        $this->guard($definition, $viaManagedWriter);

        $casted = $this->validate($definition, $value);

        // Phase E3: a typed-confirmation key is refused HERE, before any I/O,
        // unless the new value was spelled out verbatim — whoever the caller is.
        if ($definition->requiresTypedConfirmation && ($typedConfirmation === null || ! hash_equals((string) $casted, $typedConfirmation))) {
            throw TypedConfirmationRequiredException::for($definition->key);
        }

        $before = $this->effective($key);
        $actorRef = $this->actorRef();

        DB::transaction(function () use ($definition, $casted, $before, $reason, $actorRef): void {
            $setting = AppSetting::query()->updateOrCreate(
                ['key' => $definition->key],
                ['value' => $casted, 'updated_by' => Auth::id(), 'updated_by_ref' => $actorRef],
            );

            $this->audit->record(
                AuditActions::SettingsUpdated,
                $setting,
                [$definition->key => ['from' => $before->value, 'to' => $casted]],
                array_filter([
                    'source_before' => $before->source,
                    'source_after' => 'db',
                    'was_invalid' => $before->invalid ?: null,
                    'reason' => $reason,
                ], static fn ($v) => $v !== null),
            );
        });

        $this->cache->flush();

        return $this->effective($key);
    }

    /**
     * Remove the stored override so the config default applies again.
     *
     * @throws AuthorizationException|ReadOnlySettingException
     */
    public function reset(string $key, ?string $reason = null): EffectiveSetting
    {
        $definition = $this->registry->require($key);
        $this->guard($definition);

        $before = $this->effective($key);

        if (! $before->stored) {
            return $before;
        }

        DB::transaction(function () use ($definition, $before, $reason): void {
            /** @var AppSetting|null $setting */
            $setting = AppSetting::query()->where('key', $definition->key)->first();
            $setting?->delete();

            $this->audit->record(
                AuditActions::SettingsReset,
                null,
                [$definition->key => ['from' => $before->storedValue, 'to' => $definition->default()]],
                array_filter([
                    'source_before' => $before->source,
                    'source_after' => $definition->hasEnvOverride() ? 'env' : 'default',
                    'was_invalid' => $before->invalid ?: null,
                    'reason' => $reason,
                ], static fn ($v) => $v !== null),
            );
        });

        $this->cache->flush();

        return $this->effective($key);
    }

    /**
     * Cast + validate a candidate value against the registry without writing.
     *
     * @throws InvalidSettingValueException
     */
    public function validate(SettingDefinition $definition, mixed $value): mixed
    {
        if (is_string($value) && $definition->nullable && trim($value) === '') {
            $value = null;
        }

        try {
            $casted = $definition->cast($value);
        } catch (InvalidArgumentException $e) {
            throw new InvalidSettingValueException($definition->key, [$e->getMessage()]);
        }

        $validator = Validator::make(['value' => $casted], ['value' => $definition->rules]);

        if ($validator->fails()) {
            throw new InvalidSettingValueException($definition->key, $validator->errors()->all());
        }

        if ($definition->type === SettingType::Enum && ! in_array($casted, $definition->options, true)) {
            throw new InvalidSettingValueException($definition->key, ['القيمة يجب أن تكون إحدى: '.implode(', ', $definition->options).'.']);
        }

        if ($definition->type === SettingType::Template && is_string($casted)) {
            $errors = PromptTemplate::validate($casted, $definition->placeholders, $definition->requiredPlaceholders);

            if ($errors !== []) {
                throw new InvalidSettingValueException($definition->key, $errors);
            }
        }

        return $casted;
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, array{value: mixed}>  $stored
     */
    private function resolve(SettingDefinition $definition, array $stored): EffectiveSetting
    {
        $hasRow = array_key_exists($definition->key, $stored);
        $storedValue = $hasRow ? $stored[$definition->key]['value'] : null;

        // 1) Emergency env override wins.
        if ($definition->precedence === SettingPrecedence::Emergency && $definition->hasEnvOverride()) {
            return new EffectiveSetting($definition, $definition->cast($definition->envOverride()), 'env', $hasRow, $storedValue);
        }

        // 2) Stored value — only if it still satisfies the registry.
        if ($hasRow) {
            try {
                return new EffectiveSetting($definition, $this->validate($definition, $storedValue), 'db', true, $storedValue);
            } catch (InvalidSettingValueException $e) {
                Log::warning('sanad.settings.invalid_stored_value', ['key' => $definition->key]);

                return new EffectiveSetting($definition, $definition->default(), 'default', true, $storedValue, true, implode(' ', $e->errors));
            }
        }

        // 3) Config default.
        return new EffectiveSetting($definition, $definition->default(), 'default', false, null);
    }

    /**
     * The stored rows, cached briefly. Tolerates a missing table (before the
     * migration ran) and an unreachable database by falling back to "nothing
     * stored" — the defaults keep the pipeline alive.
     *
     * @return array<string, array{value: mixed}>
     */
    private function storedValues(): array
    {
        return $this->cache->remember('values', static function (): array {
            try {
                if (! Schema::hasTable('app_settings')) {
                    return [];
                }

                $rows = [];

                foreach (AppSetting::query()->get(['key', 'value']) as $row) {
                    $rows[$row->key] = ['value' => $row->value];
                }

                return $rows;
            } catch (Throwable $e) {
                Log::warning('sanad.settings.storage_unavailable', ['error' => $e::class]);

                return [];
            }
        });
    }

    /**
     * @throws AuthorizationException|ReadOnlySettingException
     */
    private function guard(SettingDefinition $definition, bool $viaManagedWriter = false): void
    {
        if ($definition->readOnly) {
            throw ReadOnlySettingException::for($definition->key);
        }

        if ($definition->managed && ! $viaManagedWriter) {
            // Phase C4: only the cutover service may write this key.
            throw ReadOnlySettingException::for($definition->key);
        }

        $user = Auth::user();

        if ($user !== null) {
            if (! $user->can($definition->permission->value)) {
                throw new AuthorizationException("Missing permission [{$definition->permission->value}] for setting [{$definition->key}].");
            }

            return;
        }

        // No authenticated user: only console (operators) may write.
        if (! app()->runningInConsole()) {
            throw new AuthorizationException("Unauthenticated write to setting [{$definition->key}].");
        }
    }

    private function actorRef(): string
    {
        $id = Auth::id();

        return $id !== null ? 'user:'.$id : (app()->runningInConsole() ? 'console' : 'system');
    }
}
