<?php

declare(strict_types=1);

namespace App\Contracts\Security;

/**
 * A model that declares which of its attributes are secrets. The explicit
 * declaration is the primary redaction source (SecretRedactor); the
 * name/value pattern fallback is only a defensive second layer.
 */
interface HasSensitiveAttributes
{
    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array;
}
