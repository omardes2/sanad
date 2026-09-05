<?php

declare(strict_types=1);

namespace App\Support\Security;

use JsonSerializable;
use LogicException;
use SensitiveParameter;
use Stringable;

/**
 * A secret held in memory (Phase C3). Every accidental exit is masked or
 * refused: string casts, JSON, var_dump/dd, serialization and Livewire
 * hydration can never carry the plaintext. The ONLY way out is reveal(),
 * which the adapters call at the moment they build the Authorization header.
 *
 * fingerprint() (16 hex of SHA-256) and last4() are the display forms.
 */
final class SecretString implements JsonSerializable, Stringable
{
    public const MASK = '[secret]';

    public const FINGERPRINT_LENGTH = 16;

    private string $value;

    public function __construct(#[SensitiveParameter] string $value)
    {
        $this->value = $value;
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function isEmpty(): bool
    {
        return trim($this->value) === '';
    }

    public function fingerprint(): string
    {
        return self::fingerprintOf($this->value);
    }

    public function last4(): string
    {
        return mb_substr($this->value, -4);
    }

    public function equals(SecretString $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public static function fingerprintOf(#[SensitiveParameter] string $value): string
    {
        return substr(hash('sha256', $value), 0, self::FINGERPRINT_LENGTH);
    }

    public function __toString(): string
    {
        return self::MASK;
    }

    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK, 'fingerprint' => $this->fingerprint()];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A SecretString must never be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A SecretString must never be unserialized.');
    }

    public function __clone()
    {
        // Cloning keeps the value private; nothing to do.
    }
}
