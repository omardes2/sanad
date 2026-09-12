<?php

declare(strict_types=1);

namespace App\Data\FollowUps;

use App\Models\FollowUp;

/**
 * The answer to "is this inbound message a reply to a follow-up ask, and to
 * WHICH one?" — together with the reason when it is not.
 *
 * There is no general inbound→outbound reply correlation in the platform: a
 * WhatsApp message carries no reference to the message it answers. So
 * correlation here is ESTABLISHED rather than read, from facts that are all
 * stored: who owns the loop, that it is awaiting an answer, that the message came
 * after the ask and inside a bounded window, on the same channel, and that it is
 * the ONLY such loop.
 *
 * When any of those fails the answer is `null` and the reason says which — never
 * a guess. A bare «تمام» must not close a loop merely because one exists.
 */
final readonly class FollowUpCorrelation
{
    private function __construct(
        public ?FollowUp $followUp,
        /** `correlated` | `none` | `ambiguous` | `not_asked` | `too_late` | `before_ask` | `channel_mismatch` */
        public string $reason,
    ) {}

    public static function correlated(FollowUp $followUp): self
    {
        return new self($followUp, 'correlated');
    }

    public static function refused(string $reason): self
    {
        return new self(null, $reason);
    }

    public function ok(): bool
    {
        return $this->followUp !== null;
    }

    /**
     * Is the refusal one the subscriber could clear by saying more?
     *
     * `ambiguous` is the case worth distinguishing: the subscriber DID answer
     * something, but more than one loop could be the subject, so the model should
     * ask which — rather than the domain picking the most recent and hoping.
     */
    public function ambiguous(): bool
    {
        return $this->reason === 'ambiguous';
    }
}
