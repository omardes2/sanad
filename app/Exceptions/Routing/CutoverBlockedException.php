<?php

declare(strict_types=1);

namespace App\Exceptions\Routing;

use RuntimeException;

/**
 * A cutover was refused by a readiness failure, a same-route guard, a missing
 * or wrong typed confirmation, or an environment override. Message is safe
 * to display; `blockers` lists every reason.
 */
final class CutoverBlockedException extends RuntimeException
{
    /**
     * @param  list<string>  $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(implode(' ', $blockers));
    }
}
