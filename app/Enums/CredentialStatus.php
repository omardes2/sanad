<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a vault credential (Phase C3): a new secret or a rotation is
 * inserted `pending` and never affects the runtime; after a Test Connection it
 * is `activated` (the previous active row is revoked in the same transaction);
 * a `revoked` row is history only. Rows are never deleted.
 */
enum CredentialStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Active => 'فعّال',
            self::Revoked => 'ملغى',
        };
    }
}
