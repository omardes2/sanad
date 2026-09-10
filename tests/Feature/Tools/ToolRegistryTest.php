<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolFieldType;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolDefinitionException;
use App\Exceptions\Tools\ToolRuleException;
use App\Support\Rbac\Permission;
use App\Support\Tools\ToolDefinition;
use App\Support\Tools\ToolField;
use App\Support\Tools\ToolKey;
use App\Support\Tools\ToolRegistry;
use App\Support\Tools\ToolSchema;

/**
 * Phase F1 — the registry is CODE, immutable and versioned, and it fails
 * closed: a duplicate or invalid definition throws while the registry is being
 * built (here, at the first test that touches it), an unknown key or version is
 * refused, and a definition can declare nothing executable.
 */
function definition(array $overrides = []): ToolDefinition
{
    return ToolDefinition::of(...array_merge([
        'key' => 'demo.action',
        'version' => 1,
        'title' => 'Demo',
        'summary' => 'A definition used by the contract tests only.',
        'capability' => ToolCapability::TasksWrite,
        'sideEffect' => ToolSideEffect::Write,
        'input' => ToolSchema::of([ToolField::of('title', ToolFieldType::String, max: 120)]),
        'output' => ToolSchema::of([ToolField::of('task_id', ToolFieldType::Integer, max: 999)]),
    ], $overrides));
}

/** A registry whose declarations are supplied by the test. */
function registryOf(array $definitions): ToolRegistry
{
    return new class($definitions) extends ToolRegistry
    {
        public function __construct(private readonly array $declarations) {}

        protected function declare(): array
        {
            return $this->declarations;
        }
    };
}

it('ships exactly the declared contracts, each one metadata only', function () {
    $registry = app(ToolRegistry::class);

    // F1's three, plus the minimal write set of F3-V1, plus durable memory, plus
    // recurrence, plus follow-up. A shipped version is never edited: `reminder.create@1` stays
    // exactly as it was beside `@2`, `memory.read@1` keeps returning counts
    // beside the `@2` that returns content, and recurrence arrived as its OWN
    // tools rather than as a third version of `reminder.create` — a series and a
    // single occurrence are different objects with different identifiers, and a
    // follow-up loop is a third object again.
    expect(array_keys($registry->all()))->toBe([
        'memory.read@1',
        'memory.read@2',
        'memory.write@1',
        'memory.forget@1',
        'task.create@1',
        'task.list@1',
        'task.complete@1',
        'reminder.cancel@1',
        'reminder.create@1',
        'reminder_schedule.create@1',
        'reminder_schedule.list@1',
        'reminder_schedule.cancel@1',
        'follow_up.create@1',
        'follow_up.list@1',
        'follow_up.resolve@1',
        'follow_up.cancel@1',
        'reminder.create@2',
    ]);

    foreach ($registry->all() as $key => $definition) {
        expect($definition->describe()['key'])->toBe($key)
            ->and($definition->capability)->toBeInstanceOf(ToolCapability::class)
            ->and($definition->sideEffect)->toBeInstanceOf(ToolSideEffect::class);
    }
});

it('refuses a duplicate key@version instead of letting the second one win', function () {
    $registry = registryOf([definition(), definition(['title' => 'Impostor'])]);

    expect(fn () => $registry->all())->toThrow(ToolDefinitionException::class, 'declared twice');
});

it('allows the same tool name at two versions and keeps them independent', function () {
    $registry = registryOf([definition(), definition(['version' => 2, 'title' => 'Demo v2'])]);

    expect($registry->has('demo.action', 1))->toBeTrue()
        ->and($registry->has('demo.action', 2))->toBeTrue()
        ->and($registry->require('demo.action', 1)->title)->toBe('Demo')
        ->and($registry->require('demo.action', 2)->title)->toBe('Demo v2')
        ->and($registry->versionsOf('demo.action'))->toHaveCount(2);
});

it('refuses an unknown tool, an unknown version and a key written without one', function () {
    $registry = app(ToolRegistry::class);

    expect(fn () => $registry->require('task.create', 2))->toThrow(ToolDefinitionException::class, 'Unknown tool [task.create@2]')
        ->and(fn () => $registry->require('nope.nothing', 1))->toThrow(ToolDefinitionException::class, 'Unknown tool')
        ->and(fn () => $registry->requireKey('task.create'))->toThrow(ToolDefinitionException::class, 'name@version')
        ->and(fn () => $registry->requireKey('task.create@0'))->toThrow(ToolDefinitionException::class)
        ->and($registry->requireKey('task.create@1')->key->value())->toBe('task.create@1')
        ->and($registry->has('task.create', 1))->toBeTrue();
});

it('holds every definition immutable: readonly, built only through of(), and the same instance on every read', function () {
    $registry = app(ToolRegistry::class);
    $first = $registry->requireKey('task.create@1');

    expect((new ReflectionClass(ToolDefinition::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(ToolDefinition::class))->isFinal())->toBeTrue()
        ->and((new ReflectionMethod(ToolDefinition::class, '__construct'))->isPrivate())->toBeTrue()
        ->and($registry->requireKey('task.create@1'))->toBe($first); // the registry hands back the same frozen object

    expect(fn () => $first->title = 'changed')->toThrow(Error::class);
});

it('rejects an input payload with an unknown field, a missing required field or an out-of-bound value — and canonicalises in declaration order', function () {
    $input = app(ToolRegistry::class)->requireKey('task.create@1')->input;

    expect(fn () => $input->validate(['title' => 'x', 'evil' => 'rm -rf']))->toThrow(ToolRuleException::class, 'Unknown field(s): evil')
        ->and(fn () => $input->validate([]))->toThrow(ToolRuleException::class, 'Field [title] is required')
        ->and(fn () => $input->validate(['title' => str_repeat('a', 121)]))->toThrow(ToolRuleException::class, 'at most 120')
        ->and(fn () => $input->validate(['title' => 'x', 'priority' => 'urgent']))->toThrow(ToolRuleException::class, 'must be one of: low, normal, high')
        ->and(fn () => $input->validate(['title' => 'x', 'due_on' => '2026-13-40']))->toThrow(ToolRuleException::class, 'YYYY-MM-DD')
        ->and($input->validate(['priority' => 'high', 'title' => '  Buy milk ']))->toBe(['title' => 'Buy milk', 'priority' => 'high'])
        ->and($input->canonical(['priority' => 'high', 'title' => 'Buy milk']))->toBe('{"title":"Buy milk","priority":"high"}');
});

it('closes the OUTPUT contract too: an undeclared result field is refused', function () {
    $output = app(ToolRegistry::class)->requireKey('reminder.create@1')->output;

    expect($output->names())->toBe(['reminder_id', 'scheduled_for'])
        ->and(fn () => $output->validate(['reminder_id' => 7, 'scheduled_for' => '2026-09-08T09:00', 'raw' => '…']))->toThrow(ToolRuleException::class, 'Unknown field(s): raw')
        ->and(fn () => $output->validate(['reminder_id' => 7]))->toThrow(ToolRuleException::class, 'Field [scheduled_for] is required')
        ->and($output->validate(['reminder_id' => '7', 'scheduled_for' => '2026-09-08T09:00']))->toBe(['reminder_id' => 7, 'scheduled_for' => '2026-09-08T09:00']);
});

it('refuses an impossible side-effect / approval combination and never lets an irreversible tool disable approval', function () {
    expect(fn () => definition(['sideEffect' => ToolSideEffect::Irreversible, 'requiresApproval' => false]))->toThrow(ToolDefinitionException::class, 'irreversible and cannot disable approval')
        ->and(fn () => definition(['sideEffect' => ToolSideEffect::Read, 'requiresApproval' => true]))->toThrow(ToolDefinitionException::class, 'nothing to approve')
        ->and(fn () => definition(['sideEffect' => ToolSideEffect::ExternalWrite, 'maxRetries' => 2]))->toThrow(ToolDefinitionException::class, 'at-most-once')
        ->and(fn () => definition(['sideEffect' => ToolSideEffect::Irreversible, 'requiresApproval' => true, 'maxRetries' => 1]))->toThrow(ToolDefinitionException::class, 'at-most-once')
        ->and(definition(['sideEffect' => ToolSideEffect::Irreversible, 'requiresApproval' => true])->needsApproval())->toBeTrue()
        ->and(definition(['sideEffect' => ToolSideEffect::ExternalWrite, 'requiresApproval' => true])->needsApproval())->toBeTrue()
        ->and(definition()->needsApproval())->toBeFalse();

    // An external write may opt in or out; the enum decides what is even askable.
    expect(ToolSideEffect::Irreversible->requiresApprovalAlways())->toBeTrue()
        ->and(ToolSideEffect::Read->mayRequireApproval())->toBeFalse()
        ->and(ToolSideEffect::Read->retryable())->toBeTrue()
        ->and(ToolSideEffect::ExternalWrite->retryable())->toBeFalse()
        ->and(ToolSideEffect::Irreversible->retryable())->toBeFalse();
});

it('refuses definitions and keys that are out of shape: bad names, empty titles, unbounded fields, silly timeouts', function () {
    expect(fn () => ToolKey::of('Task.Create', 1))->toThrow(ToolDefinitionException::class, 'valid dotted identifier')
        ->and(fn () => ToolKey::of('task', 1))->toThrow(ToolDefinitionException::class)
        ->and(fn () => ToolKey::of('task.create', 0))->toThrow(ToolDefinitionException::class, 'between 1 and 999')
        ->and(fn () => definition(['title' => '']))->toThrow(ToolDefinitionException::class)
        ->and(fn () => definition(['timeoutMs' => 1]))->toThrow(ToolDefinitionException::class, 'timeout')
        ->and(fn () => definition(['timeoutMs' => 999_999]))->toThrow(ToolDefinitionException::class, 'timeout')
        ->and(fn () => definition(['maxRetries' => 99]))->toThrow(ToolDefinitionException::class, 'retries')
        ->and(fn () => definition(['rateLimitPerHour' => 0]))->toThrow(ToolDefinitionException::class, 'rate limit')
        ->and(fn () => ToolField::of('Title', ToolFieldType::String))->toThrow(ToolDefinitionException::class, 'lower-case identifier')
        ->and(fn () => ToolField::of('title', ToolFieldType::String, max: 99_999))->toThrow(ToolDefinitionException::class, 'bound')
        ->and(fn () => ToolField::of('mode', ToolFieldType::Enum, options: []))->toThrow(ToolDefinitionException::class, 'non-empty list')
        ->and(fn () => ToolField::of('mode', ToolFieldType::Enum, options: ['a', 'a']))->toThrow(ToolDefinitionException::class, 'distinct')
        ->and(fn () => ToolField::of('title', ToolFieldType::String, options: ['a']))->toThrow(ToolDefinitionException::class, 'must not declare options')
        ->and(fn () => ToolSchema::of([ToolField::of('a', ToolFieldType::String), ToolField::of('a', ToolFieldType::String)]))->toThrow(ToolDefinitionException::class, 'twice');
});

it('lets a definition declare NOTHING executable: no class, no URL, no SQL, no shell, no raw permission string', function () {
    $properties = array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(ToolDefinition::class))->getProperties());
    sort($properties);

    expect($properties)->toBe(['capability', 'input', 'key', 'maxRetries', 'output', 'rateLimitPerHour', 'requiresApproval', 'sideEffect', 'summary', 'timeoutMs', 'title']);

    foreach (['handler', 'class', 'callable', 'url', 'endpoint', 'sql', 'query', 'command', 'shell', 'permission', 'script', 'template'] as $forbidden) {
        expect(in_array($forbidden, $properties, true))->toBeFalse("ToolDefinition must not carry a [{$forbidden}] property");
    }

    // The registry source itself declares no handler wiring in F1: definitions are metadata.
    $source = php_strip_whitespace(app_path('Support/Tools/ToolRegistry.php'));
    expect(preg_match('/(shell_exec|exec\(|system\(|eval\(|DB::|Http::|file_get_contents|new \$)/', $source))->toBe(0);
});

it('maps every capability to a permission from the code allowlist and to a real Permission case', function () {
    foreach (ToolCapability::cases() as $capability) {
        $permission = $capability->operatorPermission();

        expect($permission)->toBeInstanceOf(Permission::class)
            ->and(in_array($permission, ToolCapability::ALLOWED_OPERATOR_PERMISSIONS, true))->toBeTrue($capability->value)
            ->and(Permission::tryFrom($permission->value))->not->toBeNull();
    }

    // Every shipped tool names a capability that exists in the enum — there is no free-form capability anywhere.
    foreach (app(ToolRegistry::class)->all() as $definition) {
        expect(ToolCapability::tryFrom($definition->capability->value))->not->toBeNull()
            ->and(app(ToolRegistry::class)->forCapability($definition->capability))->toContain($definition);
    }
});
