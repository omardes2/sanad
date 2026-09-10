<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Enums\MemoryCategory;
use App\Enums\ReminderCancelScope;
use App\Enums\ReminderPattern;
use App\Enums\ReminderScheduleStatus;
use App\Enums\ToolCapability;
use App\Enums\ToolFieldType;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolDefinitionException;
use App\Services\Memory\MemoryService;
use App\Services\Reminders\ReminderScheduleService;

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
     * The declarations themselves.
     *
     * Phase F1 shipped three contracts; F3-V1 adds the minimal write set the
     * V1 launch scope names; Phase G adds durable personal memory. A shipped
     * version is frozen, so `reminder.create@1` (external_write + approval)
     * stays exactly as it was and simply is not executable in V1 — the local
     * scheduling write it should have been is `reminder.create@2` — and
     * `memory.read@1` keeps returning counts while `@2` returns content.
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
                key: 'memory.read', version: 2,
                title: 'قراءة الذاكرة',
                summary: 'يعيد ذاكرات المشترك المطابقة بمحتواها ضمن حدّ معلن. قراءة فقط: لا يكتب ولا يرسل شيئًا.',
                capability: ToolCapability::MemoryRead,
                sideEffect: ToolSideEffect::Read,
                input: ToolSchema::of([
                    ToolField::of('query', ToolFieldType::String, required: true, max: 200),
                    ToolField::of('limit', ToolFieldType::Integer, required: false, max: MemoryService::RECALL_MAX),
                ]),
                // The content THIS version returns is what lets the model answer
                // «شو بتعرف عني؟». It reaches the model for the turn and is not
                // written to the invocation row — see `ToolOutputPersistence`.
                output: ToolSchema::of([
                    ToolField::of('memories', ToolFieldType::ListOfRows, required: true, max: MemoryService::RECALL_MAX, items: ToolSchema::of([
                        ToolField::of('content', ToolFieldType::String, required: true, max: 300),
                        ToolField::of('category', ToolFieldType::Enum, required: true, options: MemoryCategory::options()),
                        ToolField::of('importance', ToolFieldType::Integer, required: true, max: 5),
                    ])),
                    ToolField::of('truncated', ToolFieldType::Boolean, required: true),
                ]),
                maxRetries: 2,
            ),
            ToolDefinition::of(
                key: 'memory.write', version: 1,
                title: 'حفظ في الذاكرة',
                summary: 'يحفظ معلومة طلب المشترك تذكّرها. لا يُنفَّذ إلا إذا كان الطلب صريحًا في رسالة المشترك نفسها.',
                capability: ToolCapability::MemoryWrite,
                // A local, reversible row: forgetting archives it, and nothing
                // leaves the platform.
                sideEffect: ToolSideEffect::Write,
                input: ToolSchema::of([
                    ToolField::of('content', ToolFieldType::String, required: true, max: 300),
                    ToolField::of('category', ToolFieldType::Enum, required: true, options: MemoryCategory::options()),
                    ToolField::of('importance', ToolFieldType::Integer, required: false, max: 5),
                ]),
                // No memory id in, no memory id out for the model to name later:
                // whether this was a new memory or an existing one refreshed is
                // the SERVER's answer, by fingerprint.
                output: ToolSchema::of([
                    ToolField::of('memory_id', ToolFieldType::Integer, required: true, max: 999999999),
                    ToolField::of('created', ToolFieldType::Boolean, required: true),
                ]),
                rateLimitPerHour: 30,
            ),
            ToolDefinition::of(
                key: 'memory.forget', version: 1,
                title: 'نسيان من الذاكرة',
                summary: 'يؤرشف ذاكرة واحدة يطابقها الوصف. لا يؤرشف أكثر من واحدة، ويرفض الوصف الملتبس.',
                capability: ToolCapability::MemoryWrite,
                sideEffect: ToolSideEffect::Write,
                input: ToolSchema::of([
                    ToolField::of('query', ToolFieldType::String, required: true, max: 200),
                ]),
                output: ToolSchema::of([
                    ToolField::of('forgotten', ToolFieldType::Integer, required: true, max: 1),
                ]),
                rateLimitPerHour: 30,
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
                key: 'task.list', version: 1,
                title: 'قائمة المهام',
                summary: 'يعرض مهام المشترك نفسه ضمن نطاق معلن وبعدد محدود. قراءة فقط: لا يكتب ولا يرسل شيئًا.',
                capability: ToolCapability::TasksWrite,
                sideEffect: ToolSideEffect::Read,
                input: ToolSchema::of([
                    ToolField::of('scope', ToolFieldType::Enum, required: false, options: ['open', 'today', 'overdue', 'completed']),
                    ToolField::of('limit', ToolFieldType::Integer, required: false, max: 20),
                ]),
                output: ToolSchema::of([
                    ToolField::of('tasks', ToolFieldType::ListOfRows, required: true, max: 20, items: ToolSchema::of([
                        ToolField::of('task_id', ToolFieldType::Integer, required: true, max: 999999999),
                        ToolField::of('title', ToolFieldType::String, required: true, max: 60),
                        ToolField::of('status', ToolFieldType::Enum, required: true, options: ['pending', 'in_progress', 'completed', 'cancelled']),
                        ToolField::of('due_on', ToolFieldType::String, required: false, max: 10),
                    ])),
                    ToolField::of('total', ToolFieldType::Integer, required: true, max: 999999),
                    ToolField::of('truncated', ToolFieldType::Boolean, required: true),
                ]),
                maxRetries: 2,
            ),
            ToolDefinition::of(
                key: 'task.complete', version: 1,
                title: 'إنهاء مهمة',
                summary: 'يضع مهمة المشترك نفسه في حالة «منجزة». تغيير محلي قابل للتراجع، وتكراره لا يغيّر شيئًا.',
                capability: ToolCapability::TasksWrite,
                sideEffect: ToolSideEffect::Write,
                input: ToolSchema::of([
                    ToolField::of('task_id', ToolFieldType::Integer, required: true, max: 999999999),
                ]),
                output: ToolSchema::of([
                    ToolField::of('task_id', ToolFieldType::Integer, required: true, max: 999999999),
                    ToolField::of('completed_at', ToolFieldType::DateTime, required: true),
                ]),
            ),
            ToolDefinition::of(
                key: 'reminder.cancel', version: 1,
                title: 'إلغاء تذكير',
                summary: 'يلغي تذكيرًا لم يُرسَل بعد للمشترك نفسه. لا يلغي تذكيرًا قيد الإرسال أو أُرسل أو فشل إرساله.',
                capability: ToolCapability::RemindersWrite,
                sideEffect: ToolSideEffect::Write,
                input: ToolSchema::of([
                    ToolField::of('reminder_id', ToolFieldType::Integer, required: true, max: 999999999),
                ]),
                output: ToolSchema::of([
                    ToolField::of('reminder_id', ToolFieldType::Integer, required: true, max: 999999999),
                    ToolField::of('cancelled_at', ToolFieldType::DateTime, required: true),
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
            /*
             * RECURRENCE IS ITS OWN OBJECT, with its own tools. `reminder.create@2`
             * stays exactly as it is — a one-time reminder is not a degenerate
             * series — and a `schedule_id` is never interchangeable with a
             * `reminder_id`: one names a definition, the other names a single
             * occurrence that can be claimed, delivered and cancelled on its own.
             */
            ToolDefinition::of(
                key: 'reminder_schedule.create', version: 1,
                title: 'إنشاء تذكير متكرِّر',
                summary: 'يجدول سلسلة تذكيرات متكرِّرة للمشترك نفسه بنمط يومي أو أسبوعي أو شهري بوقت محلي. الجدولة كتابة محلية؛ كل مرّة تُسلَّم لاحقًا كتذكير مستقل.',
                capability: ToolCapability::RemindersWrite,
                // A local, reversible definition row. It sends nothing and even
                // creates no occurrence — materialisation does that separately —
                // which is the same reasoning that made `reminder.create@2` a
                // `write` where the frozen `@1` was an `external_write`.
                sideEffect: ToolSideEffect::Write,
                input: ToolSchema::of([
                    ToolField::of('title', ToolFieldType::String, required: true, max: 120),
                    ToolField::of('pattern', ToolFieldType::Enum, required: true, options: ReminderPattern::values()),
                    // A LOCAL wall clock, `HH:MM`. Not a datetime: the whole point
                    // of a recurring schedule is that it has no single instant.
                    ToolField::of('local_time', ToolFieldType::String, required: true, max: 5),
                    // ISO weekdays as `1,4` — a bounded string, because a tool
                    // field is a closed scalar and a list of integers is not one.
                    // Parsed and refused in the domain, never half-understood here.
                    ToolField::of('weekdays', ToolFieldType::String, required: false, max: 20),
                    ToolField::of('day_of_month', ToolFieldType::Integer, required: false, max: 31),
                    ToolField::of('starts_on', ToolFieldType::Date, required: false),
                    ToolField::of('ends_on', ToolFieldType::Date, required: false),
                ]),
                output: ToolSchema::of([
                    ToolField::of('schedule_id', ToolFieldType::Integer, required: true, max: 999999999),
                    ToolField::of('pattern', ToolFieldType::Enum, required: true, options: ReminderPattern::values()),
                    ToolField::of('recurrence', ToolFieldType::String, required: true, max: 120),
                    // The subscriber's own wall clock — the only form that means
                    // anything to them, and computed by the same planner that
                    // will materialise it rather than a second formula.
                    ToolField::of('next_occurrence_local', ToolFieldType::String, required: true, max: 20),
                ]),
                rateLimitPerHour: 20,
            ),
            ToolDefinition::of(
                key: 'reminder_schedule.list', version: 1,
                title: 'عرض التذكيرات المتكرِّرة',
                summary: 'يعيد سلاسل التذكيرات المتكرِّرة الفعّالة للمشترك نفسه ضمن حدّ معلن. قراءة فقط: لا يكتب ولا يرسل شيئًا.',
                // A SEPARATE capability from writing: listing what already exists
                // is a narrower permission than creating more.
                capability: ToolCapability::RemindersRead,
                sideEffect: ToolSideEffect::Read,
                input: ToolSchema::of([
                    ToolField::of('limit', ToolFieldType::Integer, required: false, max: ReminderScheduleService::LIST_MAX),
                    ToolField::of('include_terminated', ToolFieldType::Boolean, required: false),
                ]),
                /*
                 * WHY THIS TOOL IS REQUIRED FOR V1. Days after setting a series
                 * up, a subscriber says «وقف تذكير الدوا اليومي». Without a
                 * bounded, subscriber-owned way to discover which series that is,
                 * the model would have to remember a `schedule_id` from an old
                 * turn or invent one — and an invented id is a cancellation of
                 * someone's real reminder. The ids here come from the
                 * subscriber's own rows and from nowhere else.
                 */
                output: ToolSchema::of([
                    ToolField::of('schedules', ToolFieldType::ListOfRows, required: true, max: ReminderScheduleService::LIST_MAX, items: ToolSchema::of([
                        ToolField::of('schedule_id', ToolFieldType::Integer, required: true, max: 999999999),
                        ToolField::of('title', ToolFieldType::String, required: true, max: 120),
                        ToolField::of('pattern', ToolFieldType::Enum, required: true, options: ReminderPattern::values()),
                        ToolField::of('recurrence', ToolFieldType::String, required: true, max: 120),
                        ToolField::of('status', ToolFieldType::Enum, required: true, options: ReminderScheduleStatus::values()),
                        ToolField::of('next_occurrence_local', ToolFieldType::String, required: false, max: 20),
                    ])),
                    ToolField::of('truncated', ToolFieldType::Boolean, required: true),
                ]),
                maxRetries: 2,
            ),
            ToolDefinition::of(
                key: 'reminder_schedule.cancel', version: 1,
                title: 'إلغاء تذكير متكرِّر',
                summary: 'يوقف سلسلة تذكيرات متكرِّرة للمشترك نفسه، ويلغي مرّاتها غير المُرسَلة. لا يمسّ مرّة قيد الإرسال أو أُرسلت أو فشلت.',
                capability: ToolCapability::RemindersWrite,
                sideEffect: ToolSideEffect::Write,
                input: ToolSchema::of([
                    ToolField::of('schedule_id', ToolFieldType::Integer, required: true, max: 999999999),
                    /*
                     * TWO SCOPES ONLY. Cancelling ONE occurrence is not here
                     * because an occurrence is an ordinary reminder and
                     * `reminder.cancel@1` already cancels one by `reminder_id`.
                     * Keeping it out is what keeps the identities clean — and
                     * stops a model that meant "skip tonight" from ending a
                     * series.
                     */
                    ToolField::of('scope', ToolFieldType::Enum, required: true, options: ReminderCancelScope::values()),
                ]),
                output: ToolSchema::of([
                    ToolField::of('schedule_id', ToolFieldType::Integer, required: true, max: 999999999),
                    ToolField::of('scope', ToolFieldType::Enum, required: true, options: ReminderCancelScope::values()),
                    ToolField::of('cancelled_occurrences', ToolFieldType::Integer, required: true, max: 999999),
                    ToolField::of('terminated', ToolFieldType::Boolean, required: true),
                ]),
            ),
            ToolDefinition::of(
                key: 'reminder.create', version: 2,
                title: 'إنشاء تذكير',
                summary: 'يجدول تذكيرًا واحدًا للمشترك نفسه في وقت UTC محدد. الجدولة كتابة محلية؛ الإرسال لاحقًا شأن نظام الإشعارات.',
                capability: ToolCapability::RemindersWrite,
                // The reminder ROW is a local, reversible write. What leaves the
                // platform is the later delivery, which belongs to the
                // notification subsystem and not to this contract — that is the
                // whole reason `@1` (external_write + approval) is frozen and
                // this version exists.
                sideEffect: ToolSideEffect::Write,
                input: ToolSchema::of([
                    ToolField::of('title', ToolFieldType::String, required: true, max: 120),
                    ToolField::of('remind_at', ToolFieldType::DateTime, required: true),
                ]),
                output: ToolSchema::of([
                    ToolField::of('reminder_id', ToolFieldType::Integer, required: true, max: 999999999),
                    ToolField::of('scheduled_for', ToolFieldType::DateTime, required: true),
                ]),
                rateLimitPerHour: 20,
            ),
        ];
    }
}
