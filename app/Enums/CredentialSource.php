<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the credential an adapter runs with came from (Phase C3).
 */
enum CredentialSource: string
{
    case Vault = 'vault';
    case Env = 'env';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Vault => 'الخزنة',
            self::Env => 'البيئة',
            self::None => 'لا شيء',
        };
    }
}
