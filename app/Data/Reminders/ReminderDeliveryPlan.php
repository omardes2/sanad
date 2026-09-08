<?php

declare(strict_types=1);

namespace App\Data\Reminders;

use App\Data\OutboundTemplate;
use App\Enums\ReminderFailureReason;

/**
 * The decision taken BEFORE any request leaves Sanad: may this reminder be
 * sent right now, and in which permitted form.
 *
 * There is deliberately no fourth option. We never send a free-form message
 * "to see" whether the provider allows it — a proactive message outside the
 * permitted window is a policy question with a knowable answer, not a network
 * failure to discover by trying.
 */
final readonly class ReminderDeliveryPlan
{
    private function __construct(
        public bool $permitted,
        public ?OutboundTemplate $template = null,
        public ?ReminderFailureReason $refusal = null,
    ) {}

    /** Inside the window where a normal message is allowed. */
    public static function freeForm(): self
    {
        return new self(true);
    }

    /** Outside it, but an approved template is configured. */
    public static function template(OutboundTemplate $template): self
    {
        return new self(true, $template);
    }

    /** Not permitted, for a bounded and reportable reason. */
    public static function refused(ReminderFailureReason $reason): self
    {
        return new self(false, null, $reason);
    }
}
