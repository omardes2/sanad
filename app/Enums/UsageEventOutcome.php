<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened to the user-facing operation that a billable invocation
 * belonged to. The ledger records what the provider CONSUMED (that cost is
 * real either way); this field says whether the operation also reached the
 * user, so "billable" is never conflated with "succeeded for the user".
 *
 * A provider that consumed nothing billable produces NO ledger row at all.
 */
enum UsageEventOutcome: string
{
    /** Provider consumption happened and the user-facing operation completed. */
    case Succeeded = 'succeeded';

    /** Provider consumption happened, but a later stage (delivery, tool, ...) failed. */
    case DownstreamFailed = 'downstream_failed';
}
