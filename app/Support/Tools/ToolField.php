<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Enums\ToolFieldType;
use App\Exceptions\Tools\ToolDefinitionException;
use App\Exceptions\Tools\ToolRuleException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * One field of a tool's input or output contract (Phase F1): a name, a shape,
 * whether it is required, and its BOUND. Every field is bounded — a string has
 * a maximum length, a number has a maximum magnitude, an enum has its closed
 * list — so no tool can accept an unbounded payload.
 */
final readonly class ToolField
{
    private const NAME = '/^[a-z][a-z0-9_]{0,39}$/';

    public const MAX_STRING = 2000;

    /**
     * @param  list<string>  $options  the closed list for an enum field
     */
    private function __construct(
        public string $name,
        public ToolFieldType $type,
        public bool $required,
        public int $max,
        public array $options,
    ) {}

    /**
     * @param  list<string>  $options
     */
    public static function of(string $name, ToolFieldType $type, bool $required = true, int $max = 191, array $options = []): self
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw ToolDefinitionException::of("Tool field [{$name}] is not a valid lower-case identifier.");
        }

        if ($max < 1 || ($type === ToolFieldType::String && $max > self::MAX_STRING)) {
            throw ToolDefinitionException::of("Tool field [{$name}] needs a bound between 1 and ".self::MAX_STRING.'.');
        }

        if ($type === ToolFieldType::Enum) {
            if ($options === [] || count($options) !== count(array_unique($options))) {
                throw ToolDefinitionException::of("Tool field [{$name}] is an enum and needs a non-empty list of distinct options.");
            }

            foreach ($options as $option) {
                if (! is_string($option) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $option) !== 1) {
                    throw ToolDefinitionException::of("Tool field [{$name}] has an option that is not a lower-case identifier.");
                }
            }
        } elseif ($options !== []) {
            throw ToolDefinitionException::of("Tool field [{$name}] is not an enum and must not declare options.");
        }

        return new self($name, $type, $required, $max, array_values($options));
    }

    /**
     * Cast and bound ONE value, or refuse it. The refusal names the field and
     * the rule — never the value, so nothing a caller sent is echoed back.
     *
     * @throws ToolRuleException
     */
    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            ToolFieldType::String => $this->string($value),
            ToolFieldType::Integer => $this->integer($value),
            ToolFieldType::Decimal => $this->decimal($value),
            ToolFieldType::Boolean => $this->boolean($value),
            ToolFieldType::Date => $this->temporal($value, '!Y-m-d', 'YYYY-MM-DD'),
            ToolFieldType::DateTime => $this->temporal($value, '!Y-m-d\TH:i', 'YYYY-MM-DDTHH:MM'),
            ToolFieldType::Enum => $this->enum($value),
        };
    }

    private function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw $this->refuse('must be a string');
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $this->max) {
            throw $this->refuse('must be a non-empty string of at most '.$this->max.' characters');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw $this->refuse('must not contain control characters');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?[0-9]{1,18}$/', trim($value)) === 1) {
            $int = (int) trim($value);
        } else {
            throw $this->refuse('must be an integer');
        }

        if (abs($int) > $this->max) {
            throw $this->refuse('must be an integer of magnitude at most '.$this->max);
        }

        return $int;
    }

    private function decimal(mixed $value): string
    {
        $raw = is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? trim($value) : null);

        if ($raw === null || preg_match('/^-?[0-9]{1,15}(\.[0-9]{1,6})?$/', $raw) !== 1) {
            throw $this->refuse('must be a decimal with at most 6 fractional digits');
        }

        if (abs((float) $raw) > $this->max) {
            throw $this->refuse('must be a decimal of magnitude at most '.$this->max);
        }

        return $raw;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, ['true', 'false', 0, 1, '0', '1'], true)) {
            return in_array($value, ['true', 1, '1'], true);
        }

        throw $this->refuse('must be a boolean');
    }

    private function temporal(mixed $value, string $format, string $shape): string
    {
        if (! is_string($value)) {
            throw $this->refuse("must be a {$shape} string (UTC)");
        }

        try {
            $at = CarbonImmutable::createFromFormat($format, trim($value), 'UTC');
        } catch (Throwable) {
            $at = false;
        }

        $canonical = $at === false ? null : $at->format(ltrim($format, '!'));

        // The round trip must be exact: an overflowing date (2026-13-40) parses
        // into something valid, and silently accepting it would change what the
        // caller asked for.
        if ($canonical === null || $canonical !== trim($value)) {
            throw $this->refuse("must be a {$shape} string (UTC)");
        }

        return $canonical;
    }

    private function enum(mixed $value): string
    {
        if (! is_string($value) || ! in_array(trim($value), $this->options, true)) {
            throw $this->refuse('must be one of: '.implode(', ', $this->options));
        }

        return trim($value);
    }

    private function refuse(string $expectation): ToolRuleException
    {
        return ToolRuleException::of('schema', "Field [{$this->name}] {$expectation}.");
    }
}
