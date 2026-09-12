<?php

declare(strict_types=1);

namespace App\Support\Tools;

/**
 * WHICH domain tables each write tool is allowed to change, declared in code
 * (Phase F3-V1) and read only by `DomainWriteGuard`.
 *
 * It fails closed: a tool absent from this map may write no domain table at
 * all, so adding a write tool without declaring its blast radius makes the tool
 * fail rather than silently reach anything.
 *
 * This is not where authority comes from — the executor's handler allowlist and
 * the domain services are — it is the second lock that proves the first one
 * still holds.
 */
final class ToolWriteTargets
{
    /** @var array<string, list<string>> `name@version` ⇒ the domain tables it may write */
    private const TARGETS = [
        'memory.write@1' => ['memories'],
        'memory.forget@1' => ['memories'],
        'task.create@1' => ['tasks'],
        'task.complete@1' => ['tasks'],
        'reminder.create@2' => ['reminders'],
        'reminder.cancel@1' => ['reminders'],
        // Creating a series touches the DEFINITION only. The occurrence rows are
        // written by the materialiser, which is not a tool and is not reachable
        // from a model turn at all.
        'reminder_schedule.create@1' => ['reminder_schedules'],
        // Cancelling touches both: the definition is terminated and its own
        // pending occurrences are cancelled, in one transaction.
        'reminder_schedule.cancel@1' => ['reminder_schedules', 'reminders'],
        // Opening a loop touches the DEFINITION only. The ask rows are written by
        // the materialiser, which is not a tool and is not reachable from a model
        // turn at all.
        'follow_up.create@1' => ['follow_ups'],
        // Resolving closes the loop and cancels the ask that is no longer needed.
        'follow_up.resolve@1' => ['follow_ups', 'reminders'],
        'follow_up.cancel@1' => ['follow_ups', 'reminders'],
    ];

    /** @return list<string> */
    public static function for(ToolKey $key): array
    {
        return self::TARGETS[$key->value()] ?? [];
    }

    /** @return list<string> every tool that declares a write target */
    public static function keys(): array
    {
        return array_keys(self::TARGETS);
    }
}
