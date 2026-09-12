<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Rbac\Permission;

/**
 * The capability a tool needs (Phase F1) — the layer between a tool and the two
 * INDEPENDENT gates that guard it:
 *
 *  1. SUBSCRIBER CONSENT — durable, per (subscriber, capability), granted by
 *     the subscriber or by an operator on their behalf. Absence is NOT GRANTED;
 *     it is never a role and never implied by any permission.
 *  2. OPERATOR AUTHORIZATION — the RBAC permission an operator needs to
 *     administer this capability for a subscriber. Mapped here from a CODE
 *     ALLOWLIST (`ALLOWED_OPERATOR_PERMISSIONS`) — never a free-form string,
 *     never a value read from the database.
 *
 * Neither gate implies the other: an operator holding the permission has no
 * consent of their own, and a subscriber's consent grants no permission.
 * Adding a capability is a case here plus its mapping — nothing else can
 * introduce one.
 */
enum ToolCapability: string
{
    case MemoryRead = 'memory.read';

    /**
     * Phase G — writing durable personal memory. It is a DIFFERENT decision
     * from reading it: a subscriber may well want Sanad to use what it already
     * knows without letting it store anything new, and one consent must not
     * quietly grant the other.
     */
    case MemoryWrite = 'memory.write';

    case TasksWrite = 'tasks.write';

    case RemindersWrite = 'reminders.write';

    /**
     * Reading the subscriber's own recurring schedules so the model can tell
     * WHICH series they mean days later («وقف تذكير الدوا اليومي»).
     *
     * A SEPARATE capability from RemindersWrite, and for the same reason memory
     * splits read from write: a subscriber may be happy for Sanad to list what
     * it already scheduled without letting it schedule anything new, and one
     * consent must never quietly grant the other. It is also the narrower of
     * the two, so a deployment can offer discovery without offering creation.
     */
    case RemindersRead = 'reminders.read';

    /**
     * Creating and ending a follow-up loop (Phase H3) — a LICENCE TO ASK LATER,
     * which is why it is not `reminders.write`. A reminder is a message the
     * subscriber asked for at a time they chose; a follow-up is Sanad returning
     * to an unanswered question of its own accord, up to a bounded number of
     * times. A subscriber may well want the first without the second.
     */
    case FollowUpsWrite = 'follow_ups.write';

    /**
     * Reading the subscriber's own open follow-ups, so «شو الأشياء اللي لسا
     * بتتابع معي عليها؟» can be answered and «وقف متابعة البنك» can name a real
     * loop instead of an invented id. Narrower than the write capability, and
     * split from it for the same reason memory splits read from write.
     */
    case FollowUpsRead = 'follow_ups.read';

    /**
     * The only operator permissions a capability may ever map to (Phase F1:
     * administering a subscriber's consent is subscriber management). A
     * mapping outside this list fails the registry contract test.
     *
     * @var list<Permission>
     */
    public const ALLOWED_OPERATOR_PERMISSIONS = [Permission::SubscribersManage];

    /** The permission an OPERATOR needs to grant or revoke this capability for a subscriber. */
    public function operatorPermission(): Permission
    {
        return match ($this) {
            self::MemoryRead, self::MemoryWrite, self::TasksWrite,
            self::RemindersWrite, self::RemindersRead,
            self::FollowUpsWrite, self::FollowUpsRead => Permission::SubscribersManage,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MemoryRead => 'قراءة الذاكرة',
            self::MemoryWrite => 'حفظ الذاكرة وتعديلها',
            self::TasksWrite => 'إنشاء المهام وتعديلها',
            self::RemindersWrite => 'إنشاء التذكيرات وتعديلها',
            self::RemindersRead => 'قراءة تذكيرات المشترك المتكرِّرة',
            self::FollowUpsWrite => 'المتابعة حتى الإنجاز',
            self::FollowUpsRead => 'قراءة متابعات المشترك المفتوحة',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
