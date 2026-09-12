<?php

declare(strict_types=1);

namespace App\Support\FollowUps;

use App\Enums\MessageDirection;
use App\Models\Message;

/**
 * Did the SUBSCRIBER ask Sanad to follow up — in the message being processed
 * right now? (Phase H3)
 *
 * WHY THIS EXISTS. A follow-up is a LICENCE TO SPEAK LATER, unprompted, more than
 * once. A model proposing one is a suggestion, not authority: left ungated,
 * «بكرا بدفع الفاتورة» would become a loop that messages the subscriber for days
 * because the model judged it helpful. So the create path asks a question the
 * SERVER can answer from stored evidence — does the subscriber's own message
 * contain an instruction to follow up? «تابع معي إذا دفعت» does; the bare
 * statement does not.
 *
 * THE ASYMMETRY IS THE DESIGN, exactly as for memory. A false negative costs one
 * loop the subscriber can ask for again in five words. A false positive costs
 * unsolicited proactive messages, an approved-template send each, and the
 * annoyance the "no spam loop" requirement exists to prevent. So the list stays
 * short, literal and conservative.
 *
 * THE PHRASE SET IS DISJOINT FROM THE NEIGHBOURING SUBSYSTEMS, and that is
 * load-bearing rather than tidy:
 *  - memory's «احفظ» / «انسى» are absent — storing a fact is not asking about one;
 *  - the reminder verbs «ذكرني» and «تذكر» are absent, because «ذكرني الساعة ٩»
 *    is a reminder and one verb must never be authority for two subsystems that
 *    message the subscriber on different schedules.
 * A test proves the three sets do not overlap.
 *
 * The evidence is always an INBOUND message. Sanad's own words are never
 * authority to start following up on something.
 */
final class ExplicitFollowUpIntent
{
    /**
     * "Follow up with me / check back with me" phrasings.
     *
     * Listed as STEMS where the word is long enough to be unambiguous, because
     * Arabic attaches suffixes and «تابعني» / «تابع معي» / «تابع معاي» are the
     * same request.
     *
     * @var list<string>
     */
    private const STEMS = [
        // Arabic — follow up with me
        'تابع معي',
        'تابع معاي',
        'تابعني',
        'تابعيني',
        'تابع الموضوع',
        'تابع هاي',
        'تابع هذا',
        'تابع هاد',
        'تابع حتى',
        'المتابعة معي',
        // Arabic — ask me later / check with me
        'اسألني بعدين',
        'اسالني بعدين',
        'اسألني لاحقا',
        'اسالني لاحقا',
        'اسألني اذا',
        'اسالني اذا',
        'اسألني إذا',
        'تأكد مني',
        'تاكد مني',
        'تأكدي مني',
        'تاكدي مني',
        // English
        'follow up with me',
        'follow up on',
        'follow-up with me',
        'check with me',
        'check back with me',
        'ask me later',
        'ask me again',
        'keep following up',
        'chase me',
    ];

    /**
     * A sentence asking Sanad to STOP following up contains the same verb as one
     * asking it to start. Without this, «بطّل المتابعة» would be read as
     * authority to create the very loop it is ending.
     *
     * @var list<string>
     */
    private const NEGATIONS = [
        'بطل المتابعة',
        'بطل متابعة',
        'وقف المتابعة',
        'وقف متابعة',
        'لا تتابع',
        'ما تتابع',
        'لا تتابعني',
        'ما تتابعني',
        'بلا متابعة',
        'stop following',
        'stop follow up',
        'stop the follow up',
        'no follow up',
        'dont follow up',
        "don't follow up",
        'do not follow up',
        'cancel follow up',
        'cancel the follow up',
    ];

    /** Is this message a server-verifiable instruction to follow up on something? */
    public static function present(Message $message): bool
    {
        return self::fromSubscriber($message) && self::inText((string) $message->text_content);
    }

    /** The same decision over raw text — used by the tests and by nothing else. */
    public static function inText(string $text): bool
    {
        // A request to STOP is not a request to start, however similar the verb.
        if (FollowUpPhrases::anyStem($text, self::NEGATIONS)) {
            return false;
        }

        return FollowUpPhrases::anyStem($text, self::STEMS);
    }

    /**
     * Did the subscriber ask to STOP following up? Used by the answer reader, so
     * «وقّف المتابعة» ends the loop instead of counting as an answer to it.
     */
    public static function stopInText(string $text): bool
    {
        return FollowUpPhrases::anyStem($text, self::NEGATIONS);
    }

    /** @return list<string> exposed for the disjointness test, and nothing else */
    public static function phrases(): array
    {
        return self::STEMS;
    }

    private static function fromSubscriber(Message $message): bool
    {
        return $message->direction === MessageDirection::Inbound;
    }
}
