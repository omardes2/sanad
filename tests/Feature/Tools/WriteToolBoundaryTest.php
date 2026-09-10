<?php

declare(strict_types=1);

use App\Data\Tools\ToolCallRequest;
use App\Enums\ToolCapability;
use App\Enums\ToolFieldType;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolTransitionException;
use App\Models\Memory;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\ToolInvocation;
use App\Models\ToolInvocationEvent;
use App\Services\Tasks\TaskService;
use App\Services\Tools\ReadToolExecutor;
use App\Services\Tools\StaleInvocationSweeper;
use App\Services\Tools\ToolInvocationStore;
use App\Services\Tools\WriteToolExecutor;
use App\Support\Tools\CanonicalInput;
use App\Support\Tools\InvocationKey;
use App\Support\Tools\ToolDefinition;
use App\Support\Tools\ToolField;
use App\Support\Tools\ToolInputPersistence;
use App\Support\Tools\ToolKey;
use App\Support\Tools\ToolRegistry;
use App\Support\Tools\ToolSchema;
use App\Support\Tools\ToolWriteTargets;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase F3-V1 — the two boundaries around a write, and the transactional
 * guarantee that makes a local write exactly once.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'UTC'));
    [$this->subscriber, $this->message] = f3Subject();
});

/** A definition built here, so a combination the registry does not ship can still be proven. */
function f3Definition(string $name, int $version, ToolSideEffect $sideEffect, bool $requiresApproval = false): ToolDefinition
{
    return ToolDefinition::of(
        key: $name, version: $version,
        title: 'عقد اختباري',
        summary: 'عقد يثبت أسبقية الرفض بين صنف الأثر والموافقة.',
        capability: ToolCapability::TasksWrite,
        sideEffect: $sideEffect,
        input: ToolSchema::of([ToolField::of('title', ToolFieldType::String, required: true, max: 120)]),
        output: ToolSchema::of([ToolField::of('task_id', ToolFieldType::Integer, required: true, max: 999999999)]),
        requiresApproval: $requiresApproval,
    );
}

function f3Sweeper(): StaleInvocationSweeper
{
    return new StaleInvocationSweeper(app(ToolInvocationStore::class), app(ToolRegistry::class));
}

function f3Request(ToolDefinition $definition, array $arguments): ToolCallRequest
{
    return new ToolCallRequest(
        message: test()->message,
        subscriber: test()->subscriber,
        definition: $definition,
        callIndex: 1,
        input: CanonicalInput::of($definition->input, $arguments),
        key: InvocationKey::of((int) test()->message->getKey(), 1),
    );
}

it('answers the EXECUTABLE CLASS first and the approval requirement second, and never lets one mask the other', function () {
    $cases = [
        // [side effect, requires approval] => the one answer
        'read' => [ToolSideEffect::Read, false, null],                                              // executable, no approval possible
        'write' => [ToolSideEffect::Write, false, null],                                            // executable
        'write requiring approval' => [ToolSideEffect::Write, true, ToolInvocationRefusalReason::ApprovalRequired],
        'external_write' => [ToolSideEffect::ExternalWrite, false, ToolInvocationRefusalReason::SideEffectNotExecutable],
        'external_write requiring approval' => [ToolSideEffect::ExternalWrite, true, ToolInvocationRefusalReason::SideEffectNotExecutable],
        'irreversible' => [ToolSideEffect::Irreversible, true, ToolInvocationRefusalReason::SideEffectNotExecutable],
    ];

    $n = 0;

    foreach ($cases as $label => [$sideEffect, $approval, $expected]) {
        if ($expected === null) {
            continue; // the executable combinations are covered by their own tests
        }

        $result = f3Executor()->execute(f3Request(f3Definition('probe.case_'.(++$n), 1, $sideEffect, $approval), ['title' => 'x']));

        expect($result->refusal)->toBe($expected, $label)
            ->and($result->invocation)->toBeNull($label)      // never a row
            ->and($result->claim)->toBeNull($label)           // never a claim
            ->and($result->executed)->toBeFalse($label);
    }

    // The class ALWAYS answers before approval: an external write that also
    // requires approval is `side_effect_not_executable`, never `approval_required`.
    $both = f3Executor()->execute(f3Request(f3Definition('probe.both', 1, ToolSideEffect::ExternalWrite, true), ['title' => 'x']));
    expect($both->refusal)->toBe(ToolInvocationRefusalReason::SideEffectNotExecutable);

    // A local write that requires approval is the ONLY way to reach `approval_required`.
    $approval = f3Executor()->execute(f3Request(f3Definition('probe.needs_ok', 1, ToolSideEffect::Write, true), ['title' => 'x']));
    expect($approval->refusal)->toBe(ToolInvocationRefusalReason::ApprovalRequired);

    expect(ToolInvocation::count())->toBe(0)
        ->and(ToolInvocationEvent::count())->toBe(0)
        ->and(Task::count())->toBe(0);
});

it('refuses the shipped reminder.create@1 as NOT EXECUTABLE — never as approval_required', function () {
    $definition = app(ToolRegistry::class)->require('reminder.create', 1);

    // It is external_write AND requires approval; the class must win.
    expect($definition->sideEffect)->toBe(ToolSideEffect::ExternalWrite)
        ->and($definition->needsApproval())->toBeTrue();

    $result = f3Executor()->call($this->message, 'reminder.create@1', ['title' => 'x', 'remind_at' => '2026-09-09T09:00']);

    expect($result->refusal)->toBe(ToolInvocationRefusalReason::SideEffectNotExecutable)
        ->and($result->refusal)->not->toBe(ToolInvocationRefusalReason::ApprovalRequired)
        ->and($result->invocation)->toBeNull()
        ->and(Reminder::count())->toBe(0)
        ->and(ToolInvocation::count())->toBe(0);

    // …while `@2`, the local scheduling write, executes.
    expect(f3Executor()->call($this->message, 'reminder.create@2', ['title' => 'x', 'remind_at' => '2026-09-09T09:00'])->invocation->status)
        ->toBe(ToolInvocationStatus::Succeeded)
        ->and(Reminder::count())->toBe(1);
});

it('rolls the DOMAIN WRITE back with the settlement when the terminal transaction cannot commit', function () {
    // A domain service that writes and then fails: the write is inside the
    // settlement transaction, so it must not survive.
    app()->bind(TaskService::class, fn () => new class
    {
        public function create($subscriber, array $input, ?int $sourceMessageId = null): array
        {
            Task::query()->create([
                'user_id' => $subscriber->getKey(),
                'title' => $input['title'],
                'status' => 'pending',
            ]);

            throw new RuntimeException('the process dies here');
        }
    });

    $result = f3Executor()->call($this->message, 'task.create@1', ['title' => 'لن تبقى']);
    $row = $result->invocation;

    expect($row->status)->toBe(ToolInvocationStatus::Failed)
        ->and($row->failure_kind)->toBe(ToolInvocationFailureKind::ToolError)
        ->and($row->output)->toBeNull()
        // The task the service created is GONE: neither the domain row nor a
        // succeeded invocation exists.
        ->and(Task::count())->toBe(0)
        ->and(f2Usage($row))->toHaveCount(1);   // it did enter running, so the work is metered
});

it('loses a settlement race without leaving the domain write behind', function () {
    $request = f2Plan()->one($this->message, 'task.create@1', ['title' => 'مرة واحدة']);
    $invocation = f2Store()->begin(f2Store()->authorize(f2Store()->claim($request)->invocation), fn (): bool => true);

    // Another process settles the invocation first…
    f2Store()->succeed($invocation, ['task_id' => 1], 5);

    // …so this one's domain write must roll back with its refused transition.
    expect(fn () => f2Store()->succeedWith($invocation, function (): array {
        Task::query()->create(['user_id' => $this->subscriber->id, 'title' => 'نسخة ثانية', 'status' => 'pending']);

        return ['task_id' => 999];
    }, 5))->toThrow(ToolTransitionException::class);

    expect(Task::count())->toBe(0)                    // the loser wrote nothing
        ->and($invocation->fresh()->status)->toBe(ToolInvocationStatus::Succeeded)
        ->and($invocation->fresh()->output)->toBe(['task_id' => 1])
        ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $invocation->id)->count())->toBe(4)
        ->and(f2Audits($invocation->fresh()))->toHaveCount(1)
        ->and(f2Usage($invocation->fresh()))->toHaveCount(1);
});

it('sweeps a crashed local write as timed_out, and the domain table proves nothing was written', function () {
    // A write invocation left `running` — exactly what a killed process leaves,
    // because its domain mutation never committed.
    $request = f2Plan()->one($this->message, 'task.create@1', ['title' => 'انهيار']);
    $row = f2Store()->begin(f2Store()->authorize(f2Store()->claim($request)->invocation), fn (): bool => true);

    expect($row->status)->toBe(ToolInvocationStatus::Running)
        ->and($row->side_effect)->toBe(ToolSideEffect::Write)
        ->and(Task::count())->toBe(0);

    $this->travelTo(CarbonImmutable::now('UTC')->addHour());

    expect(f3Sweeper()->sweep())->toBe(1)
        ->and($row->fresh()->status)->toBe(ToolInvocationStatus::TimedOut)
        ->and($row->fresh()->output)->toBeNull()
        ->and(Task::count())->toBe(0)                  // nothing was duplicated, because nothing existed
        ->and(f2Audits($row->fresh()))->toHaveCount(1)
        ->and(f2Usage($row->fresh()))->toHaveCount(1);

    // The sweeper covers exactly the classes whose mutation shares this database.
    expect(StaleInvocationSweeper::SWEEPABLE)->toBe([ToolSideEffect::Read, ToolSideEffect::Write]);
});

it('fails a write tool that touches a table outside its declared scope, and keeps that table unchanged', function () {
    app()->bind(TaskService::class, fn () => new class
    {
        public function create($subscriber, array $input, ?int $sourceMessageId = null): array
        {
            $task = Task::query()->create(['user_id' => $subscriber->getKey(), 'title' => $input['title'], 'status' => 'pending']);

            // Out of scope: `task.create@1` declares `tasks` and nothing else.
            Reminder::factory()->create(['user_id' => $subscriber->getKey()]);

            return ['task_id' => (int) $task->getKey()];
        }
    });

    $row = f3Executor()->call($this->message, 'task.create@1', ['title' => 'خارج النطاق'])->invocation;

    expect($row->status)->toBe(ToolInvocationStatus::Failed)
        ->and($row->failure_kind)->toBe(ToolInvocationFailureKind::Internal)
        ->and(Reminder::count())->toBe(0)   // rolled back with everything else
        ->and(Task::count())->toBe(0);

    // Every executable write declares its blast radius, and the map fails closed.
    foreach (WriteToolExecutor::executableKeys() as $key) {
        expect(ToolWriteTargets::for(ToolKey::parse($key)))->not->toBe([], $key);
    }

    expect(ToolWriteTargets::for(ToolKey::of('unlisted.tool', 1)))->toBe([])
        ->and(ToolWriteTargets::keys())->toBe(WriteToolExecutor::executableKeys());
});

it('keeps every write tool metadata-only on the tool layer: no argument value is ever stored', function () {
    $registry = app(ToolRegistry::class);

    foreach ($registry->all() as $definition) {
        expect(ToolInputPersistence::persistable($definition->key))->toBe([], $definition->key->value());
    }

    // The titles ARE the subscriber's words. They belong in the subscriber's own
    // row — and nowhere in the tool layer.
    $secret = 'زوجتي تكره القهوة وموعدها الخميس';
    $row = f3Executor()->call($this->message, 'task.create@1', ['title' => $secret])->invocation;

    expect(Task::query()->sole()->title)->toBe($secret)          // the product working correctly
        ->and($row->input)->toBe([])
        ->and($row->input_fields)->toBe(['title']);

    $serialised = json_encode([
        ToolInvocation::query()->get()->toArray(),
        ToolInvocationEvent::query()->get()->toArray(),
        DB::table('audit_logs')->get(),
        DB::table('usage_events')->get(),
    ], JSON_UNESCAPED_UNICODE);

    expect(str_contains($serialised, $secret))->toBeFalse()
        ->and(str_contains($serialised, 'زوجتي'))->toBeFalse()
        ->and($serialised)->toContain($row->input_hash);
});

it('routes each class to its own executor and keeps the F2 read path intact', function () {
    $registry = app(ToolRegistry::class);

    foreach ($registry->all() as $definition) {
        $key = $definition->key->value();
        $executable = match ($definition->sideEffect) {
            ToolSideEffect::Read => in_array($key, ReadToolExecutor::executableKeys(), true),
            ToolSideEffect::Write => ! $definition->needsApproval() && in_array($key, WriteToolExecutor::executableKeys(), true),
            default => false,
        };

        // Exactly the eight write tools plus the four read tools are executable.
        expect($executable)->toBe(in_array($key, [
            'memory.read@1', 'memory.read@2', 'task.list@1', 'reminder_schedule.list@1',
            'memory.write@1', 'memory.forget@1', 'task.create@1', 'task.complete@1',
            'reminder.create@2', 'reminder.cancel@1',
            'reminder_schedule.create@1', 'reminder_schedule.cancel@1',
        ], true), $key);
    }

    // The read path still works through the router.
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'a note about coffee']);
    f3Consent($this->subscriber, ToolCapability::MemoryRead);

    expect(f3Executor()->call(f2Message($this->subscriber), 'memory.read@1', ['query' => 'coffee'])->output())
        ->toBe(['matches' => 1, 'truncated' => false]);
});
