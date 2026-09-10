<?php

declare(strict_types=1);

use App\Enums\ReminderScheduleStatus;
use App\Enums\ReminderStatus;
use App\Enums\ToolCapability;
use App\Enums\ToolSideEffect;
use App\Models\Reminder;
use App\Models\ReminderSchedule;
use App\Services\Tools\ReadToolExecutor;
use App\Services\Tools\WriteToolExecutor;
use App\Support\Tools\ToolOutputPersistence;
use App\Support\Tools\ToolRegistry;
use App\Support\Tools\ToolWriteTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The RECURRENCE TOOL SURFACE: three tools, each with its own identity contract.
 *
 * The rule this file defends is that a `schedule_id` and a `reminder_id` are
 * different things and no tool may blur them. A series is a definition; an
 * occurrence is one reminder that can be claimed, delivered and cancelled on its
 * own. Collapsing the two is how a model that meant "skip tonight" ends a series.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    whatsappConfigure();
    config(['reminders.enabled' => true, 'reminders.recurrence.enabled' => true]);
});

function recTool(string $key)
{
    return app(ToolRegistry::class)->all()[$key] ?? null;
}

/*
|--------------------------------------------------------------------------
| The contracts
|--------------------------------------------------------------------------
*/

it('ships the three recurrence tools with the right classes and capabilities', function () {
    expect(recTool('reminder_schedule.create@1')->sideEffect)->toBe(ToolSideEffect::Write)
        ->and(recTool('reminder_schedule.create@1')->capability)->toBe(ToolCapability::RemindersWrite)
        // A listing is a READ under its OWN, narrower capability: a subscriber may
        // want Sanad to see what it already scheduled without letting it schedule
        // more, and one consent must not quietly grant the other.
        ->and(recTool('reminder_schedule.list@1')->sideEffect)->toBe(ToolSideEffect::Read)
        ->and(recTool('reminder_schedule.list@1')->capability)->toBe(ToolCapability::RemindersRead)
        ->and(recTool('reminder_schedule.cancel@1')->sideEffect)->toBe(ToolSideEffect::Write)
        ->and(recTool('reminder_schedule.cancel@1')->capability)->toBe(ToolCapability::RemindersWrite);
});

it('leaves the one-time reminder tools exactly as they were', function () {
    expect(recTool('reminder.create@2')->sideEffect)->toBe(ToolSideEffect::Write)
        ->and(recTool('reminder.create@2')->input->names())->toBe(['title', 'remind_at'])
        ->and(recTool('reminder.cancel@1')->input->names())->toBe(['reminder_id'])
        // The frozen contract stays frozen and stays unexecutable.
        ->and(recTool('reminder.create@1')->sideEffect)->toBe(ToolSideEffect::ExternalWrite)
        ->and(WriteToolExecutor::executableKeys())->not->toContain('reminder.create@1');
});

it('never lets a model supply the owner, the timezone or the channel', function (string $key) {
    $names = recTool($key)->input->names();

    foreach (['user_id', 'subscriber_id', 'timezone', 'channel'] as $forbidden) {
        expect($names)->not->toContain($forbidden);
    }
})->with([
    'reminder_schedule.create@1',
    'reminder_schedule.list@1',
    'reminder_schedule.cancel@1',
]);

it('offers only future and series as cancellation scopes — never a single occurrence', function () {
    $scope = recTool('reminder_schedule.cancel@1')->input->fields['scope'];

    // Cancelling ONE occurrence is `reminder.cancel@1` on a `reminder_id`. Keeping
    // it out of the schedule tool is what keeps the two identities apart.
    expect($scope->options)->toBe(['future', 'series'])
        ->and($scope->options)->not->toContain('occurrence');
});

it('identifies a series by schedule_id and an occurrence by reminder_id, never interchangeably', function () {
    expect(recTool('reminder_schedule.cancel@1')->input->names())->toContain('schedule_id')
        ->and(recTool('reminder_schedule.cancel@1')->input->names())->not->toContain('reminder_id')
        ->and(recTool('reminder.cancel@1')->input->names())->toContain('reminder_id')
        ->and(recTool('reminder.cancel@1')->input->names())->not->toContain('schedule_id');
});

it('declares the blast radius of each recurrence write', function () {
    expect(ToolWriteTargets::for(recTool('reminder_schedule.create@1')->key))->toBe(['reminder_schedules'])
        // Cancelling terminates the definition AND cancels its own occurrences, in
        // one transaction, so it legitimately touches both tables.
        ->and(ToolWriteTargets::for(recTool('reminder_schedule.cancel@1')->key))->toBe(['reminder_schedules', 'reminders'])
        // The occurrence rows are written by the materialiser, which is not a tool
        // and is unreachable from a model turn.
        ->and(ToolWriteTargets::keys())->toBe(WriteToolExecutor::executableKeys());
});

it('routes each recurrence tool to the executor its class belongs to', function () {
    expect(ReadToolExecutor::executableKeys())->toContain('reminder_schedule.list@1')
        ->and(WriteToolExecutor::executableKeys())->toContain('reminder_schedule.create@1')
        ->and(WriteToolExecutor::executableKeys())->toContain('reminder_schedule.cancel@1')
        // And the classes are not crossed.
        ->and(ReadToolExecutor::executableKeys())->not->toContain('reminder_schedule.create@1')
        ->and(WriteToolExecutor::executableKeys())->not->toContain('reminder_schedule.list@1');
});

/*
|--------------------------------------------------------------------------
| What the listing leaves on the invocation row
|--------------------------------------------------------------------------
*/

it('redacts the listing on the invocation row, keeping shape and not titles', function () {
    $key = recTool('reminder_schedule.list@1')->key;

    expect(ToolOutputPersistence::isRedacted($key))->toBeTrue();

    $stored = ToolOutputPersistence::filter($key, [
        'schedules' => [
            ['schedule_id' => 7, 'title' => 'حبوب الضغط', 'pattern' => 'daily', 'recurrence' => 'كل يوم 09:00', 'status' => 'active', 'next_occurrence_local' => '2026-09-11 09:00'],
        ],
        'truncated' => false,
    ]);

    // «حبوب الضغط» is a health fact. On an operational row it would sit in
    // plaintext, in an operator's reach and in every backup — and an audit trail
    // needs only that a listing happened and how much it returned.
    expect($stored)->toBe(['schedules_count' => 1, 'truncated' => false])
        ->and(json_encode($stored, JSON_UNESCAPED_UNICODE))->not->toContain('حبوب الضغط');
});

it('re-derives the listing on a proven replay instead of handing back the projection', function () {
    // A queue retry replays the same slot; with only the shape on the row the
    // model could no longer answer which series the subscriber meant. Declaring it
    // rehydratable keeps the answer without ever storing it.
    expect(ToolOutputPersistence::rehydratableOnReplay(recTool('reminder_schedule.list@1')->key))->toBeTrue()
        // And only a read may ever qualify: re-deriving a write would BE a write.
        ->and(ToolOutputPersistence::rehydratableOnReplay(recTool('reminder_schedule.create@1')->key))->toBeFalse()
        ->and(ToolOutputPersistence::rehydratableOnReplay(recTool('reminder_schedule.cancel@1')->key))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Execution through the real tool path
|--------------------------------------------------------------------------
*/

it('creates a series through the write executor, and a replay creates no second one', function () {
    [$user] = recSubscriber();
    f3Consent($user, ToolCapability::RemindersWrite);
    $message = f2Message($user);

    $arguments = ['title' => 'اشرب الدوا', 'pattern' => 'daily', 'local_time' => '09:00'];

    $first = f3Executor()->call($message, 'reminder_schedule.create@1', $arguments);
    $again = f3Executor()->call($message, 'reminder_schedule.create@1', $arguments);

    expect($first->output()['schedule_id'])->toBeInt()
        // The invocation identity collapses the retry: the same message, the same
        // slot and the same canonical input is ONE invocation, so a redelivered
        // job cannot give the subscriber a second daily reminder.
        ->and($again->output()['schedule_id'])->toBe($first->output()['schedule_id'])
        ->and(ReminderSchedule::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('lists a series through the read executor', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user, ['title' => 'حبوب الضغط']);
    f3Consent($user, ToolCapability::RemindersRead);

    $result = f3Executor()->call(f2Message($user), 'reminder_schedule.list@1', []);
    $output = $result->output();

    expect($output['schedules'])->toHaveCount(1)
        ->and($output['schedules'][0]['schedule_id'])->toBe($schedule->id)
        ->and($output['schedules'][0]['title'])->toBe('حبوب الضغط')
        ->and($output['truncated'])->toBeFalse();
});

it('cancels a series through the write executor and settles its occurrences', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();
    f3Consent($user, ToolCapability::RemindersWrite);

    $result = f3Executor()->call(f2Message($user), 'reminder_schedule.cancel@1', [
        'schedule_id' => $schedule->id,
        'scope' => 'series',
    ]);

    expect($result->output()['terminated'])->toBeTrue()
        ->and($result->output()['cancelled_occurrences'])->toBeGreaterThan(0)
        ->and($schedule->refresh()->status)->toBe(ReminderScheduleStatus::Terminated)
        ->and(Reminder::query()->where('reminder_schedule_id', $schedule->id)
            ->where('status', ReminderStatus::Pending->value)->count())->toBe(0);
});

it('refuses a recurrence tool without the subscriber consent for its capability', function (string $key, array $arguments) {
    [$user] = recSubscriber();
    // No consent granted at all.

    $result = f3Executor()->call(f2Message($user), $key, $arguments);

    expect($result->output())->toBeNull()
        ->and(ReminderSchedule::query()->where('user_id', $user->id)->count())->toBe(0);
})->with([
    'create' => ['reminder_schedule.create@1', ['title' => 'اشرب الدوا', 'pattern' => 'daily', 'local_time' => '09:00']],
]);

it('refuses an input the closed schema does not declare', function () {
    [$user] = recSubscriber();
    f3Consent($user, ToolCapability::RemindersWrite);

    // A smuggled owner is refused by the contract, not by the domain.
    $result = f3Executor()->call(f2Message($user), 'reminder_schedule.create@1', [
        'title' => 'اشرب الدوا',
        'pattern' => 'daily',
        'local_time' => '09:00',
        'user_id' => 999999,
    ]);

    expect($result->output())->toBeNull()
        ->and(ReminderSchedule::query()->count())->toBe(0);
});
