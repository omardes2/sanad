<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * The proposed change would alter the route the router selects for `chat`.
 * The caller must repeat the write with the typed confirmation = the NEW
 * selected handle (e.g. "openai:gpt-4.1-mini"), so an admin cannot change
 * production routing by accident.
 */
final class RoutingChangeConfirmationRequired extends RuntimeException
{
    public function __construct(
        public readonly ?string $before,
        public readonly ?string $after,
    ) {
        parent::__construct(sprintf(
            'سيتغيّر التوجيه الفعلي لعملية chat من [%s] إلى [%s]. اكتب «%s» للتأكيد.',
            $before ?? 'لا شيء',
            $after ?? 'لا شيء',
            $after ?? '',
        ));
    }

    public function expectedConfirmation(): string
    {
        return (string) $this->after;
    }
}
