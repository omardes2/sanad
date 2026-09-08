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
        'task.create@1' => ['tasks'],
        'task.complete@1' => ['tasks'],
        'reminder.create@2' => ['reminders'],
        'reminder.cancel@1' => ['reminders'],
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
