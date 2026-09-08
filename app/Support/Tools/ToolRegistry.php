<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Enums\ToolCapability;
use App\Enums\ToolFieldType;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolDefinitionException;

/**
 * Every tool the platform knows, DECLARED IN CODE (Phase F1) — the same shape
 * as `SettingsRegistry` and `Permission`: the code is the registry, the
 * database only ever holds operational state about these keys (enabled,
 * limits, a subscriber's consent). No row, no payload and no provider can
 * introduce a tool, name a handler or widen a contract.
 *
 * The registry FAILS CLOSED:
 *   - `definitions()` is built once and every definition is validated as it is
 *     added, so an invalid one throws at boot / at the first test that touches
 *     the registry — never at a user's request;
 *   - a duplicate `key@version` throws instead of overriding the earlier one;
 *   - `require()` throws for an unknown key or an unknown version.
 *
 * F1 ships definitions only. Nothing here executes anything: there is no
 * handler, no dispatcher and no domain write — that is F2 and later.
 */
class ToolRegistry
{
    /** @var array<string, ToolDefinition>|null keyed by `name@version` */
    private ?array $definitions = null;

    /**
     * @return array<string, ToolDefinition> keyed by `name@version`, declaration order
     */
    public function all(): array
    {
        if ($this->definitions === null) {
            $this->definitions = [];

            foreach ($this->declare() as $definition) {
                $key = $definition->key->value();

                if (array_key_exists($key, $this->definitions)) {
                    throw ToolDefinitionException::of("Tool [{$key}] is declared twice; a duplicate never overrides the first.");
                }

                $this->definitions[$key] = $definition;
            }
        }

        return $this->definitions;
    }

    /** @return list<ToolDefinition> every version of one tool name, oldest first */
    public function versionsOf(string $name): array
    {
        return array_values(array_filter($this->all(), static fn (ToolDefinition $d): bool => $d->key->name === $name));
    }

    public function has(string $name, int $version): bool
    {
        return array_key_exists($name.'@'.$version, $this->all());
    }

    /**
     * The one definition for this exact name and version, or a refusal. There
     * is no "latest version" lookup anywhere in the platform: a caller states
     * the version it was written against, exactly as a conversion states its
     * rate.
     *
     * @throws ToolDefinitionException
     */
    public function require(string $name, int $version): ToolDefinition
    {
        $key = ToolKey::of($name, $version);

        return $this->all()[$key->value()]
            ?? throw ToolDefinitionException::of("Unknown tool [{$key}]. Tools are declared in code; nothing else can introduce one.");
    }

    /** `task.create@1` in one call — the same strict parsing, the same refusal. */
    public function requireKey(string $key): ToolDefinition
    {
        $parsed = ToolKey::parse($key);

        return $this->require($parsed->name, $parsed->version->value);
    }

    /** @return list<ToolDefinition> every tool needing one capability */
    public function forCapability(ToolCapability $capability): array
    {
        return array_values(array_filter($this->all(), static fn (ToolDefinition $d): bool => $d->capability === $capability));
    }

    /**
     * The declarations themselves. Phase F1 ships three safe, NON-EXECUTING
     * contracts so the registry, the capability model and the consent gate can
     * be exercised end to end without any tool being able to do anything.
     *
     * @return list<ToolDefinition>
     */
    protected function declare(): array
    {
        return [
            ToolDefinition::of(
                key: 'memory.read', version: 1,
                title: 'قراءة الذاكرة',
                summary: 'يقرأ ملاحظات المشترك المخزَّنة بحدّ أقصى معلن. قراءة فقط: لا يكتب ولا يرسل شيئًا.',
                capability: ToolCapability::MemoryRead,
                sideEffect: ToolSideEffect::Read,
                input: ToolSchema::of([
                    ToolField::of('query', ToolFieldType::String, required: true, max: 200),
                    ToolField::of('limit', ToolFieldType::Integer, required: false, max: 50),
                ]),
                output: ToolSchema::of([
                    ToolField::of('matches', ToolFieldType::Integer, required: true, max: 50),
                    ToolField::of('truncated', ToolFieldType::Boolean, required: true),
                ]),
                maxRetries: 2,
            ),
            ToolDefinition::of(
                key: 'task.create', version: 1,
                title: 'إنشاء مهمة',
                summary: 'ينشئ مهمة واحدة للمشترك نفسه بعنوان وتاريخ استحقاق اختياري. تغيير محلي قابل للتراجع.',
                capability: ToolCapability::TasksWrite,
                sideEffect: ToolSideEffect::Write,
                input: ToolSchema::of([
                    ToolField::of('title', ToolFieldType::String, required: true, max: 120),
                    ToolField::of('due_on', ToolFieldType::Date, required: false),
                    ToolField::of('priority', ToolFieldType::Enum, required: false, options: ['low', 'normal', 'high']),
                ]),
                output: ToolSchema::of([
                    ToolField::of('task_id', ToolFieldType::Integer, required: true, max: 999999999),
                ]),
            ),
            ToolDefinition::of(
                key: 'reminder.create', version: 1,
                title: 'إنشاء تذكير',
                summary: 'يجدول تذكيرًا واحدًا للمشترك نفسه في وقت UTC محدد. يُرسل لاحقًا عبر القناة، فيلزم تأكيد.',
                capability: ToolCapability::RemindersWrite,
                sideEffect: ToolSideEffect::ExternalWrite,
                input: ToolSchema::of([
                    ToolField::of('title', ToolFieldType::String, required: true, max: 120),
                    ToolField::of('remind_at', ToolFieldType::DateTime, required: true),
                ]),
                output: ToolSchema::of([
                    ToolField::of('reminder_id', ToolFieldType::Integer, required: true, max: 999999999),
                    ToolField::of('scheduled_for', ToolFieldType::DateTime, required: true),
                ]),
                requiresApproval: true,
                rateLimitPerHour: 20,
            ),
        ];
    }
}
