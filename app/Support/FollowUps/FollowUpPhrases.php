<?php

declare(strict_types=1);

namespace App\Support\FollowUps;

use App\Support\Memory\MemoryText;

/**
 * Phrase matching for the follow-up domain — the one place a piece of Arabic or
 * English text is compared against a literal list.
 *
 * TWO MATCH MODES, because one mode cannot be safe for both kinds of phrase:
 *
 *  - `word` — a whole word, with UNICODE-AWARE boundaries. Needed for short and
 *    ambiguous tokens: «اه» as a bare substring also appears inside «اهلا»، and
 *    `no` inside `now`, so a substring match would read a greeting as a yes.
 *  - `stem` — a plain substring. Safe and necessary for longer, unambiguous
 *    stems, because Arabic attaches suffixes: «دفعتها» contains «دفعت» and a
 *    whole-word match would miss exactly the replies people actually send.
 *
 * Text is compared after `MemoryText::normalize()` — NFC, tatweel and diacritics
 * removed, whitespace collapsed, ASCII lower-cased, and LETTERS DELIBERATELY NOT
 * FOLDED. The normaliser is reused rather than reimplemented: a second, slightly
 * different normaliser is how two subsystems start disagreeing about what the
 * same sentence says. Where a phrase is genuinely written with different letters
 * («اسألني» / «اسالني») both spellings are listed literally.
 */
final class FollowUpPhrases
{
    /**
     * Does $text contain any of these whole words?
     *
     * @param  list<string>  $words
     */
    public static function anyWord(string $text, array $words): bool
    {
        $normalised = MemoryText::normalize($text);

        if ($normalised === '') {
            return false;
        }

        foreach ($words as $word) {
            if (self::word($normalised, MemoryText::normalize($word))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does $text contain any of these stems, suffixes and all?
     *
     * @param  list<string>  $stems
     */
    public static function anyStem(string $text, array $stems): bool
    {
        $normalised = MemoryText::normalize($text);

        if ($normalised === '') {
            return false;
        }

        foreach ($stems as $stem) {
            $needle = MemoryText::normalize($stem);

            if ($needle !== '' && str_contains($normalised, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Does $text match any of these already-anchored regular expressions? */
    public static function anyPattern(string $text, array $patterns): bool
    {
        $normalised = MemoryText::normalize($text);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The POSITION of the earliest whole-word or stem match, or null.
     *
     * Used by the answer reader to ask a question a boolean cannot answer: does a
     * negation come BEFORE the affirmative? «ما دفعت» and «دفعت» contain the same
     * stem, and only the order separates them.
     *
     * @param  list<string>  $words
     * @param  list<string>  $stems
     */
    public static function earliestPosition(string $text, array $words = [], array $stems = []): ?int
    {
        $normalised = MemoryText::normalize($text);

        if ($normalised === '') {
            return null;
        }

        $earliest = null;

        foreach ($stems as $stem) {
            $needle = MemoryText::normalize($stem);
            $at = $needle === '' ? false : mb_strpos($normalised, $needle);

            if ($at !== false && ($earliest === null || $at < $earliest)) {
                $earliest = $at;
            }
        }

        foreach ($words as $word) {
            $at = self::wordPosition($normalised, MemoryText::normalize($word));

            if ($at !== null && ($earliest === null || $at < $earliest)) {
                $earliest = $at;
            }
        }

        return $earliest;
    }

    private static function word(string $normalised, string $word): bool
    {
        return self::wordPosition($normalised, $word) !== null;
    }

    /**
     * Unicode-aware whole-word search. The boundaries are "not a letter and not a
     * digit" on both sides, which is what makes «اه» match the one-word reply and
     * not the middle of another word; PCRE's own `\b` is ASCII-centric and would
     * treat every Arabic letter as a boundary.
     */
    private static function wordPosition(string $normalised, string $word): ?int
    {
        if ($word === '') {
            return null;
        }

        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u';

        if (preg_match($pattern, $normalised, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        // A byte offset from PCRE, converted to a character offset so it is
        // comparable with `mb_strpos` results.
        return mb_strlen(substr($normalised, 0, (int) $matches[0][1]));
    }
}
