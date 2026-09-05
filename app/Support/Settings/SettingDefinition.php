<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Enums\SettingPrecedence;
use App\Enums\SettingType;
use App\Support\Rbac\Permission;
use InvalidArgumentException;

/**
 * One registered setting: everything the platform needs to read, validate,
 * authorise and display it. Registered in code (SettingsRegistry) — the
 * database only ever stores a value for a key defined here.
 *
 * @param  list<string>  $rules  Laravel validation rules applied to the cast value
 * @param  list<string>  $options  allowed values for Enum
 * @param  list<string>  $placeholders  allowlist for Template
 * @param  list<string>  $requiredPlaceholders  must appear in a Template
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<string>  $rules
     * @param  list<string>  $options
     * @param  list<string>  $placeholders
     * @param  list<string>  $requiredPlaceholders
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public string $group,
        public string $label,
        public string $description,
        public Permission $permission,
        public SettingPrecedence $precedence,
        /** config() path holding the default (config-time env, config:cache safe). */
        public string $defaultConfigPath,
        public array $rules = [],
        public array $options = [],
        public array $placeholders = [],
        public array $requiredPlaceholders = [],
        /** Emergency only: display name of the env variable (e.g. AI_ENABLED). */
        public ?string $envKey = null,
        /** Emergency only: config() path holding the RAW env value (null = unset). */
        public ?string $overrideConfigPath = null,
        /** Displayed with effective value + source, never written from DB/UI. */
        public bool $readOnly = false,
        public bool $nullable = false,
        /** Reserved for later phases; C1 registers no sensitive setting. */
        public bool $sensitive = false,
    ) {
        if ($this->precedence === SettingPrecedence::Emergency && $this->overrideConfigPath === null) {
            throw new InvalidArgumentException("Emergency setting [{$this->key}] needs an override config path.");
        }
    }

    public function default(): mixed
    {
        return $this->cast(config($this->defaultConfigPath));
    }

    /**
     * The raw environment override, or null when the variable is not set.
     */
    public function envOverride(): mixed
    {
        if ($this->overrideConfigPath === null) {
            return null;
        }

        $raw = config($this->overrideConfigPath);

        return $raw === null || $raw === '' ? null : $raw;
    }

    public function hasEnvOverride(): bool
    {
        return $this->envOverride() !== null;
    }

    /**
     * Convert an input (form string, JSON value, config value) to the setting's
     * native type. Throws on an unconvertible value.
     *
     * @throws InvalidArgumentException
     */
    public function cast(mixed $value): mixed
    {
        if ($value === null) {
            if ($this->nullable) {
                return null;
            }

            throw new InvalidArgumentException("Setting [{$this->key}] cannot be null.");
        }

        return match ($this->type) {
            SettingType::Boolean => $this->castBool($value),
            SettingType::Integer => $this->castInt($value),
            SettingType::Float => $this->castFloat($value),
            SettingType::String, SettingType::Text, SettingType::Template, SettingType::Enum => $this->castString($value),
        };
    }

    private function castBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = is_string($value) ? strtolower(trim($value)) : $value;
        $result = filter_var($normalized, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($result === null) {
            throw new InvalidArgumentException("Setting [{$this->key}] expects a boolean.");
        }

        return $result;
    }

    private function castInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) || is_float($value)) {
            $trimmed = is_string($value) ? trim($value) : $value;

            if (is_numeric($trimmed) && (string) (int) $trimmed === (string) $trimmed) {
                return (int) $trimmed;
            }
        }

        throw new InvalidArgumentException("Setting [{$this->key}] expects an integer.");
    }

    private function castFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        throw new InvalidArgumentException("Setting [{$this->key}] expects a number.");
    }

    private function castString(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException("Setting [{$this->key}] expects text.");
    }
}
