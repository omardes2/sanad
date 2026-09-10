<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the subscriber's reply to a follow-up ask actually says — as classified by
 * the SERVER from the inbound message, never by model confidence alone.
 *
 * `Unrecognised` is the default and the most important case. It does NOT mean
 * "no": a reply Sanad cannot read is not evidence that the thing is unfinished,
 * so it leaves the loop exactly where it was, does not consume the ask budget,
 * and does NOT trigger an immediate new ask. The model may ask for
 * clarification; the domain may not guess.
 */
enum FollowUpAnswer: string
{
    /** «آه دفعت» — the loop is closed. */
    case Confirmed = 'confirmed';

    /** «لسا» — still open; the ladder may continue if budget remains. */
    case NotYet = 'not_yet';

    /** «وقّف المتابعة» — stop asking entirely. */
    case Cancel = 'cancel';

    /** Anything else. Never treated as an answer. */
    case Unrecognised = 'unrecognised';

    /** The outcomes a model may propose through the resolve tool. */
    public static function proposable(): array
    {
        return [self::Confirmed->value, self::NotYet->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
