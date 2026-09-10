<?php

declare(strict_types=1);

namespace App\Support\FollowUps;

use App\Enums\FollowUpAnswer;
use App\Enums\MessageDirection;
use App\Models\Message;

/**
 * What does this inbound message say about the loop Sanad just asked about?
 * (Phase H3)
 *
 * THE ONE RULE THAT SHAPES EVERYTHING HERE: unrecognised is not "no", and "no" is
 * not "yes". The costs are wildly asymmetric —
 *
 *   reading «لسا» as confirmed   ⇒ the loop closes on something unfinished and
 *                                  the subscriber never hears about it again;
 *   reading «آه» as not-yet      ⇒ one extra ask, inside a bounded budget;
 *   reading anything as unknown  ⇒ nothing moves, and the model may ask.
 *
 * So every ambiguity resolves AWAY from `Confirmed`, and nothing here ever
 * resolves a loop on its own: this is a reader, and the service re-checks the
 * full correlation context before any state changes.
 *
 * NEGATION IS POSITIONAL, not a flag. «ما دفعت» and «دفعت» share a stem and only
 * the order separates them, so the reader compares WHERE the negation appears
 * against where the affirmative does. A negation before the affirmative means the
 * affirmative is being denied.
 */
final class FollowUpAnswerReader
{
    /**
     * Bare affirmatives — matched as WHOLE WORDS because they are short enough to
     * hide inside other words («اه» lives inside «اهلا»، `no` inside `now`).
     *
     * @var list<string>
     */
    private const YES_WORDS = [
        'اه',
        'ايه',
        'ايوه',
        'أيوه',
        'اي',
        'نعم',
        'اكيد',
        'أكيد',
        'تم',
        'yes',
        'yep',
        'yeah',
        'yup',
        'ok',
        'okay',
        'done',
    ];

    /**
     * Affirmative stems — long enough that a substring match is safe and
     * NECESSARY, since Arabic attaches suffixes («دفعتها»، «خلصتها»).
     *
     * @var list<string>
     */
    private const YES_STEMS = [
        'دفعت',
        'خلصت',
        'خلص الموضوع',
        'عملتها',
        'عملته',
        'سويتها',
        'أنجزت',
        'انجزت',
        'تمت',
        'صار',
        'حكيت',
        'اتصلت',
        'راجعت',
        'تم الدفع',
        'paid it',
        'paid the',
        'already paid',
        'finished',
        'completed it',
        'sorted it',
        'taken care of',
    ];

    /**
     * "Not yet" — the honest negative. Checked BEFORE the affirmatives, because
     * «لسا ما دفعت» contains «دفعت».
     *
     * @var list<string>
     */
    private const NOT_YET_STEMS = [
        'لسا',
        'لسه',
        'لسة',
        'بعدني',
        'مش بعد',
        'ما بعد',
        'بعد شوي',
        'نسيت',
        'ما زبطت',
        'مش هلق',
        'مش هلأ',
        'not yet',
        'still not',
        'havent',
        "haven't",
        'have not',
        'not done',
        'nope',
        'forgot',
    ];

    /** @var list<string> bare negatives, whole-word for the same reason as YES_WORDS */
    private const NOT_YET_WORDS = [
        'لا',
        'لأ',
        'مش',
        'no',
        'not',
    ];

    /**
     * Markers that NEGATE a following affirmative. «ما دفعت»، «لم أدفع»، «مش
     * خلصت» — the affirmative stem is present and denied.
     *
     * @var list<string>
     */
    private const NEGATION_MARKERS = [
        'ما ',
        'لم ',
        'مش ',
        'لا ',
        'لسا',
        'لسه',
        'بدون',
        'not ',
        "didn't",
        'didnt',
        'did not',
        'never ',
    ];

    /** Read the subscriber's reply. Outbound messages are never an answer. */
    public static function read(Message $message): FollowUpAnswer
    {
        if ($message->direction !== MessageDirection::Inbound) {
            return FollowUpAnswer::Unrecognised;
        }

        return self::inText((string) $message->text_content);
    }

    /**
     * The same decision over raw text.
     *
     * The ORDER of these checks is the policy:
     *  1. an instruction to stop is neither a yes nor a no — it ends the loop;
     *  2. an explicit "not yet" wins over any affirmative stem it contains;
     *  3. an affirmative counts only when nothing negates it;
     *  4. everything else is unrecognised, and unrecognised changes nothing.
     */
    public static function inText(string $text): FollowUpAnswer
    {
        if (trim($text) === '') {
            return FollowUpAnswer::Unrecognised;
        }

        if (ExplicitFollowUpIntent::stopInText($text)) {
            return FollowUpAnswer::Cancel;
        }

        if (FollowUpPhrases::anyStem($text, self::NOT_YET_STEMS)
            || FollowUpPhrases::anyWord($text, self::NOT_YET_WORDS)) {
            return FollowUpAnswer::NotYet;
        }

        $yesAt = FollowUpPhrases::earliestPosition($text, self::YES_WORDS, self::YES_STEMS);

        if ($yesAt === null) {
            return FollowUpAnswer::Unrecognised;
        }

        $negationAt = FollowUpPhrases::earliestPosition($text, [], self::NEGATION_MARKERS);

        // A negation standing before the affirmative is denying it: «ما دفعت».
        if ($negationAt !== null && $negationAt < $yesAt) {
            return FollowUpAnswer::NotYet;
        }

        return FollowUpAnswer::Confirmed;
    }

    /**
     * Does this message support the outcome the model is proposing?
     *
     * The model may propose; it may not decide. A proposal the subscriber's own
     * words do not support is refused, which is what keeps "the model was fairly
     * sure" from closing a loop.
     */
    public static function supports(Message $message, FollowUpAnswer $proposed): bool
    {
        $read = self::read($message);

        return match ($proposed) {
            // A cancellation instruction also ends the loop, so it is accepted
            // wherever a confirmation is proposed? NO — it is a different
            // outcome with a different terminal state, so each must match.
            FollowUpAnswer::Confirmed => $read === FollowUpAnswer::Confirmed,
            FollowUpAnswer::NotYet => $read === FollowUpAnswer::NotYet,
            FollowUpAnswer::Cancel => $read === FollowUpAnswer::Cancel,
            FollowUpAnswer::Unrecognised => false,
        };
    }
}
