<?php

declare(strict_types=1);

namespace App\Support\Security;

use Illuminate\Database\Eloquent\Model;

/**
 * Removes secrets from anything about to be persisted or emitted (audit
 * rows, log context, exception context, exports).
 *
 * Two layers, in order:
 *  1. EXPLICIT — keys registered in SensitiveFieldRegistry and attributes a
 *     model declares sensitive;
 *  2. DEFENSIVE — well-known secret-looking key names and value shapes
 *     (vendor key prefixes, bearer tokens), so an unregistered secret still
 *     never leaks.
 *
 * A redacted string becomes "[REDACTED:<8 hex of sha256>]": the value is gone
 * but a CHANGE between two audit rows stays detectable. Null and empty values
 * stay as they are (there is nothing to protect).
 */
final class SecretRedactor
{
    public const PLACEHOLDER = '[REDACTED]';

    private const KEY_PATTERN = '/(api[_-]?key|secret|token|passw(or)?d|credential|authorization|private[_-]?key|bearer|client[_-]?secret|signing[_-]?key)/i';

    private const VALUE_PATTERN = '/^(sk-[A-Za-z0-9_-]{8,}|gsk_[A-Za-z0-9_-]{8,}|EAA[A-Za-z0-9]{16,}|Bearer\s+\S{8,}|xox[abp]-\S{8,}|AIza[0-9A-Za-z_-]{20,}|-----BEGIN [A-Z ]*PRIVATE KEY-----)/';

    public function __construct(private readonly SensitiveFieldRegistry $registry) {}

    /**
     * Redact a value; when $key is given the key name participates in the
     * decision. Arrays are walked recursively.
     */
    public function redact(mixed $value, ?string $key = null, Model|string|null $model = null, bool $underSensitiveKey = false): mixed
    {
        // A sensitive key taints everything beneath it: an audit diff such as
        // password => ['from' => …, 'to' => …] must redact both leaves.
        $sensitive = $underSensitiveKey || ($key !== null && $this->isSensitiveKey($key, $model));

        if (is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                $out[$k] = $this->redact($v, is_string($k) ? $k : null, $model, $sensitive);
            }

            return $out;
        }

        if ($value === null || $value === '' || $value === []) {
            return $value;
        }

        if ($sensitive) {
            return $this->mask($value);
        }

        if (is_string($value) && preg_match(self::VALUE_PATTERN, $value) === 1) {
            return $this->mask($value);
        }

        return $value;
    }

    /**
     * Redact a model's attribute map (e.g. for an audit "before/after" diff).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function redactAttributes(Model $model, array $attributes): array
    {
        return $this->redact($attributes, null, $model);
    }

    public function isSensitiveKey(string $key, Model|string|null $model = null): bool
    {
        $normalized = strtolower(trim($key));

        if ($this->registry->isSensitiveKey($normalized)) {
            return true;
        }

        if ($model !== null && in_array($key, $this->registry->attributesFor($model), true)) {
            return true;
        }

        return preg_match(self::KEY_PATTERN, $normalized) === 1;
    }

    public function mask(mixed $value): string
    {
        if (! is_scalar($value)) {
            return self::PLACEHOLDER;
        }

        return '[REDACTED:'.substr(hash('sha256', (string) $value), 0, 8).']';
    }
}
