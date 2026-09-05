<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * The proposed change would leave the `chat` operation with no eligible
 * candidate at all (disabling the last working route — or, under the `auto`
 * catalog source, enabling a first database model that switches routing to an
 * unconfigured catalog). Refused server-side: make a working alternative
 * available first, then change the old one.
 */
final class LastViableRouteException extends RuntimeException
{
    public static function for(string $subject): self
    {
        return new self("لا يمكن تطبيق التغيير على [{$subject}]: سيترك ذلك النظام بلا أي مرشّح صالح لعملية chat. وفّر بديلًا صالحًا أولًا ثم غيّر القديم.");
    }
}
