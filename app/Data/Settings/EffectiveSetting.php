<?php

declare(strict_types=1);

namespace App\Data\Settings;

use App\Support\Settings\SettingDefinition;

/**
 * A setting as it applies RIGHT NOW: the value, where it came from
 * ("env" override, "db" row, or config "default"), and whether a stored row
 * exists but was rejected as invalid (then the default is in force and the
 * admin page asks for a correction).
 */
final readonly class EffectiveSetting
{
    public function __construct(
        public SettingDefinition $definition,
        public mixed $value,
        public string $source,
        public bool $stored,
        public mixed $storedValue,
        public bool $invalid = false,
        public ?string $invalidReason = null,
    ) {}

    public function key(): string
    {
        return $this->definition->key;
    }

    public function envForced(): bool
    {
        return $this->source === 'env';
    }

    /**
     * Whether the value may be changed from the database/UI at all.
     */
    public function editable(): bool
    {
        return ! $this->definition->readOnly && ! $this->envForced();
    }
}
