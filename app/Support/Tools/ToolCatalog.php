<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Data\Ai\AiToolDefinition;
use App\Enums\ToolFieldType;
use App\Enums\ToolSideEffect;
use App\Services\Tools\ReadToolExecutor;
use App\Services\Tools\WriteToolExecutor;

/**
 * WHICH tools the model is shown this turn, and how they are translated for a
 * provider (Phase F4).
 *
 * EXPOSURE IS A SERVER DECISION, made here and nowhere else. A registered tool
 * is offered only when ALL of these hold:
 *   - its side-effect class is executable in V1 (`read` or local `write`);
 *   - it requires no approval (no approval mechanism exists yet);
 *   - an executor actually has a handler for that exact `name@version`.
 * Everything else — `reminder.create@1` above all — is simply never described
 * to the model. Hiding is not the control, though: `ToolExecutor` still fails
 * closed on anything that reaches it by another route, and a test proves both.
 *
 * TRANSLATION IS ADAPTER WORK. A `ToolDefinition` stays provider-independent:
 * it never learns a wire format, a JSON-Schema dialect or a provider's naming
 * rules. This class renders it into Sanad's own provider-neutral
 * `AiToolDefinition`, and each provider maps that to its own payload. Function
 * names must survive providers that allow only `[A-Za-z0-9_-]`, so the wire
 * name is `<area>_<action>__v<version>` and `resolve()` maps it back — the model
 * never sees, and never chooses, the internal key.
 */
final class ToolCatalog
{
    public function __construct(private readonly ToolRegistry $registry) {}

    /**
     * The tools that may be offered this turn, as provider-neutral definitions.
     *
     * @return list<AiToolDefinition>
     */
    public function expose(): array
    {
        return array_values(array_map(
            fn (ToolDefinition $definition): AiToolDefinition => new AiToolDefinition(
                name: self::wireName($definition->key),
                description: $definition->summary,
                parameters: self::parameters($definition->input),
            ),
            $this->executable(),
        ));
    }

    /**
     * The executable definitions themselves, in registry order.
     *
     * @return list<ToolDefinition>
     */
    public function executable(): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            static fn (ToolDefinition $d): bool => self::isExposable($d),
        ));
    }

    /** Is this contract offerable to a model in V1? */
    public static function isExposable(ToolDefinition $definition): bool
    {
        if ($definition->needsApproval()) {
            return false;   // no approval mechanism exists yet
        }

        $key = $definition->key->value();

        return match ($definition->sideEffect) {
            ToolSideEffect::Read => in_array($key, ReadToolExecutor::executableKeys(), true),
            ToolSideEffect::Write => in_array($key, WriteToolExecutor::executableKeys(), true),
            default => false,   // external_write and irreversible are never offered
        };
    }

    /** `memory.read@1` ⇒ `memory_read__v1`. Bounded and provider-safe. */
    public static function wireName(ToolKey $key): string
    {
        return str_replace('.', '_', $key->name).'__v'.$key->version->value;
    }

    /**
     * The internal key a wire name refers to, or null when the model named
     * something that is not offered this turn. Never throws on model input.
     */
    public function resolve(string $wireName): ?ToolDefinition
    {
        foreach ($this->executable() as $definition) {
            if (self::wireName($definition->key) === $wireName) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * The tool's closed input schema as JSON Schema, bounds and all. Only the
     * declared fields appear, `additionalProperties` is false, and there is no
     * shape the model could send that `ToolSchema::validate()` would not
     * re-check anyway — this describes the contract, it never becomes it.
     *
     * @return array<string, mixed>
     */
    public static function parameters(ToolSchema $schema): array
    {
        $properties = [];
        $required = [];

        foreach ($schema->fields as $name => $field) {
            $properties[$name] = self::property($field);

            if ($field->required) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function property(ToolField $field): array
    {
        return match ($field->type) {
            ToolFieldType::String => ['type' => 'string', 'maxLength' => $field->max],
            ToolFieldType::Integer => ['type' => 'integer', 'minimum' => -$field->max, 'maximum' => $field->max],
            ToolFieldType::Decimal => ['type' => 'string', 'description' => 'decimal, at most 6 fractional digits'],
            ToolFieldType::Boolean => ['type' => 'boolean'],
            ToolFieldType::Date => ['type' => 'string', 'description' => 'YYYY-MM-DD (UTC)'],
            ToolFieldType::DateTime => ['type' => 'string', 'description' => 'YYYY-MM-DDTHH:MM (UTC)'],
            ToolFieldType::Enum => ['type' => 'string', 'enum' => $field->options],
            ToolFieldType::ListOfRows => [
                'type' => 'array',
                'maxItems' => $field->max,
                'items' => self::parameters($field->items),
            ],
        };
    }
}
