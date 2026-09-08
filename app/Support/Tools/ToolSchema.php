<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolDefinitionException;
use App\Exceptions\Tools\ToolRuleException;

/**
 * A CLOSED contract: exactly these fields, nothing else (Phase F1).
 *
 * `validate()` refuses an unknown key outright — it does not ignore it, strip
 * it or pass it through. That is the whole point: whatever produced the payload
 * (a model, a provider, a queued job, a form) cannot smuggle an extra
 * instruction into a tool call, and a tool's result cannot grow a field its
 * contract never promised.
 *
 * The same class is used for input and for output, so both ends of every tool
 * are bounded by the same rule.
 */
final readonly class ToolSchema
{
    /** @param array<string, ToolField> $fields keyed by name, in declaration order */
    private function __construct(public array $fields) {}

    /**
     * @param  list<ToolField>  $fields
     */
    public static function of(array $fields): self
    {
        $keyed = [];

        foreach ($fields as $field) {
            if (! $field instanceof ToolField) {
                throw ToolDefinitionException::of('A tool schema takes ToolField instances only.');
            }

            if (array_key_exists($field->name, $keyed)) {
                throw ToolDefinitionException::of("Tool schema declares field [{$field->name}] twice.");
            }

            $keyed[$field->name] = $field;
        }

        if (count($keyed) > 20) {
            throw ToolDefinitionException::of('A tool schema is bounded to 20 fields.');
        }

        return new self($keyed);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Cast and bound a payload against this contract, in DECLARATION ORDER, so
     * the result is canonical (the same input always yields the same array and
     * therefore the same hash).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ToolRuleException
     */
    public function validate(array $payload): array
    {
        $unknown = array_diff(array_keys($payload), $this->names());

        if ($unknown !== []) {
            sort($unknown);

            throw ToolRuleException::of('schema', 'Unknown field(s): '.implode(', ', $unknown).'.');
        }

        $out = [];

        foreach ($this->fields as $name => $field) {
            $present = array_key_exists($name, $payload) && $payload[$name] !== null;

            if (! $present) {
                if ($field->required) {
                    throw ToolRuleException::of('schema', "Field [{$name}] is required.");
                }

                continue;
            }

            $out[$name] = $field->cast($payload[$name]);
        }

        return $out;
    }

    /**
     * The canonical form of a validated payload: declaration order, no
     * whitespace, no floats — the exact bytes an idempotency hash is taken of
     * (F2). Secrets never enter a tool schema, so this is safe to hash and to
     * store beside the invocation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function canonical(array $payload): string
    {
        return json_encode($this->validate($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
