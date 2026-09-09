<?php

declare(strict_types=1);

namespace App\Support\Memory;

use App\Enums\MessageDirection;
use App\Models\Message;

/**
 * Did the SUBSCRIBER ask Sanad to remember something, in the message being
 * processed right now? (Phase G)
 *
 * WHY THIS EXISTS. A model proposing `memory.write` is a suggestion, not
 * authority. Left ungated, «أنا بحب القهوة سادة» becomes a stored fact simply
 * because the model judged it useful — which is exactly the silent persistence
 * V1 forbids. So the write path asks a question the SERVER can answer from
 * stored evidence: does the subscriber's own message contain an instruction to
 * remember? «احفظ إني بحب القهوة سادة» does; the bare statement does not.
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

    /** Is this message a server-verifiable instruction to remember something? */
    public static function present(Message $message): bool
    {
        if ($message->direction !== MessageDirection::Inbound) {
            // Sanad's own outbound text can never authorise a memory write.
            return false;
        }

        return self::inText((string) $message->text_content);
    }

    /** The same decision over raw text — used by the tests and by nothing else. */
    public static function inText(string $text): bool
    {
        $normalised = MemoryText::normalize($text);

        if ($normalised === '') {
            return false;
        }

        foreach (self::PHRASES as $phrase) {
            if (str_contains($normalised, MemoryText::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }
}
