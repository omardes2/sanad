<?php

declare(strict_types=1);

namespace App\Support\Memory;

use App\Enums\MessageDirection;
use App\Models\Message;

/**
 * Did the SUBSCRIBER ask Sanad to remember — or to forget — something, in the
 * message being processed right now? (Phase G)
 *
 * WHY THIS EXISTS. A model proposing `memory.write` is a suggestion, not
 * authority. Left ungated, «أنا بحب القهوة سادة» becomes a stored fact simply
 * because the model judged it useful — which is exactly the silent persistence
 * V1 forbids. So the write path asks a question the SERVER can answer from
 * stored evidence: does the subscriber's own message contain an instruction to
 * remember? «احفظ إني بحب القهوة سادة» does; the bare statement does not.
 *
 * FORGETTING NEEDS THE SAME BAR, and for a sharper reason: it DESTROYS what the
 * subscriber deliberately kept. «بطلت أحب القهوة» is a correction — new
 * information, and quite possibly a memory worth updating — but it is not an
 * instruction to erase anything, and a model that reads it as one archives a
 * fact nobody asked it to touch. Contradiction is not authority. «انسى إني بحب
 * القهوة» is.
 *
 * WHAT IT IS AND IS NOT. It is a PERMISSION gate: it never causes a save, it
 * only allows one the model separately proposed, and consent is still checked
 * independently. It is deliberately conservative — an instruction phrased in a
 * way this list does not know is refused, and the subscriber can simply say
 * «احفظ ...». A false negative costs one unsaved memory the subscriber can
 * retry; a false positive stores personal data nobody asked to store. The
 * asymmetry is the whole design, so this list stays short and literal.
 *
 * The evidence is always an INBOUND message. Sanad's own words are never
 * authority to remember something, which is why the direction is checked
 * before the text is.
 *
 * The text is matched after `MemoryText::normalize()`, so tashkeel, tatweel
 * and spacing differences do not matter. Letters are NOT folded, so where a
 * phrase is genuinely written with different letters (إني / اني) both spellings
 * are listed literally rather than normalised together.
 */
final class ExplicitMemoryIntent
{
    /**
     * Imperative "remember / keep this" phrasings, normalised.
     *
     * Deliberately absent: bare «تذكر» and «ذكرني», because «تذكرني بكرا» and
     * «ذكرني الساعة ٩» are REMINDER requests, not memory instructions, and one
     * verb must not serve as authority for two different subsystems.
     *
     * @var list<string>
     */
    private const PHRASES = [
        // Arabic — save / store / write down
        'احفظ',
        'احفظي',
        'خزن',
        'سجل عندك',
        'سجلي عندك',
        // Arabic — keep in mind
        'خليك فاكر',
        'خليك متذكر',
        'خلي ببالك',
        'خليك عارف',
        'خلي عندك',
        // Arabic — do not forget
        'لا تنسى',
        'لا تنسي',
        'ما تنسى',
        'ما تنساش',
        'متنساش',
        // Arabic — "remember that I ..." with both spellings of the hamza
        'تذكر اني',
        'تذكر إني',
        'تذكر ان',
        'تذكر أن',
        // English
        'remember that',
        'remember i',
        'remember my',
        'save this',
        'save that',
        'note that',
        'make a note',
        'keep in mind',
        'dont forget',
        "don't forget",
    ];

    /**
     * Imperative "forget this / remove it from memory" phrasings, normalised.
     *
     * Deliberately absent: every form of mere contradiction — «بطلت أحب القهوة»،
     * «ما عدت أفضل المساء»، «غيرت رأيي». They are new information, not a
     * request to erase; treating them as authority would let the model quietly
     * delete a fact the subscriber chose to keep.
     *
     * The generic delete verbs (احذف / امسح / شيل) only count when they NAME the
     * memory — «احذف من ذاكرتك» — because on their own they belong to other
     * subsystems: «احذف المهمة» is a task, not a memory.
     *
     * @var list<string>
     */
    private const FORGET_PHRASES = [
        // Arabic — forget
        'انسى',
        'انسي',
        // Arabic — stop remembering
        'لا تضل متذكر',
        'لا تضل فاكر',
        'ما تضل متذكر',
        'ما تضل فاكر',
        'لا تتذكر',
        'ما تتذكر',
        // Arabic — delete/erase, but only from MEMORY
        'من ذاكرتك',
        'من الذاكرة',
        // English
        'forget',
        'stop remembering',
        'delete from memory',
        'remove from memory',
        'erase from memory',
    ];

    /**
     * "Do NOT forget" is a REMEMBER instruction that happens to contain the word
     * `forget`. Without this the very sentence that asks Sanad to keep something
     * would also authorise erasing it.
     *
     * @var list<string>
     */
    private const FORGET_NEGATIONS = [
        'لا تنسى',
        'لا تنسي',
        'ما تنسى',
        'ما تنساش',
        'متنساش',
        'dont forget',
        "don't forget",
        'do not forget',
        'never forget',
    ];

    /**
     * "Stop remembering" contains the verb «تذكر», so without this the very
     * sentence asking Sanad to FORGET something would also authorise writing it.
     * A negated remember is not a remember.
     *
     * @var list<string>
     */
    private const REMEMBER_NEGATIONS = [
        'لا تضل متذكر',
        'لا تضل فاكر',
        'ما تضل متذكر',
        'ما تضل فاكر',
        'لا تتذكر',
        'ما تتذكر',
        'stop remembering',
    ];

    /** Is this message a server-verifiable instruction to remember something? */
    public static function present(Message $message): bool
    {
        return self::fromSubscriber($message) && self::inText((string) $message->text_content);
    }

    /** Is this message a server-verifiable instruction to FORGET something? */
    public static function forgetPresent(Message $message): bool
    {
        return self::fromSubscriber($message) && self::forgetInText((string) $message->text_content);
    }

    /** The same decision over raw text — used by the tests and by nothing else. */
    public static function inText(string $text): bool
    {
        if (self::matches($text, self::REMEMBER_NEGATIONS)) {
            return false;
        }

        return self::matches($text, self::PHRASES);
    }

    /** The forget decision over raw text — used by the tests and by nothing else. */
    public static function forgetInText(string $text): bool
    {
        // A remember-instruction that merely contains the word `forget` is not a
        // licence to erase.
        if (self::matches($text, self::FORGET_NEGATIONS)) {
            return false;
        }

        return self::matches($text, self::FORGET_PHRASES);
    }

    /**
     * The evidence is always an INBOUND message: Sanad's own words are never
     * authority to remember or to forget anything, which is why the direction is
     * checked before the text is.
     */
    private static function fromSubscriber(Message $message): bool
    {
        return $message->direction === MessageDirection::Inbound;
    }

    /**
     * @param  list<string>  $phrases
     */
    private static function matches(string $text, array $phrases): bool
    {
        $normalised = MemoryText::normalize($text);

        if ($normalised === '') {
            return false;
        }

        foreach ($phrases as $phrase) {
            if (str_contains($normalised, MemoryText::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }
}
