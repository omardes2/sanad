<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * The platform roles (Phase C0). `super_admin` is granted every ability by a
 * Gate::before rule as well as every registry permission, so it never needs a
 * per-permission update. The other three are least-privilege sets defined in
 * RoleMatrix.
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case Finance = 'finance';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'مدير عام',
            self::Operations => 'تشغيل',
            self::Finance => 'مالية',
            self::Support => 'دعم',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
