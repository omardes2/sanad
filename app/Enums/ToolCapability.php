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

    case TasksWrite = 'tasks.write';

    case RemindersWrite = 'reminders.write';

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
            self::MemoryRead, self::TasksWrite, self::RemindersWrite => Permission::SubscribersManage,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MemoryRead => 'قراءة الذاكرة',
            self::TasksWrite => 'إنشاء المهام وتعديلها',
            self::RemindersWrite => 'إنشاء التذكيرات وتعديلها',
        };
    }
}
