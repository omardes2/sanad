<?php

declare(strict_types=1);

namespace App\Support\Memory;

use Normalizer;

/**
 * The ONE normalisation of durable-memory text (Phase G), used for the
 * duplicate fingerprint and for nothing else. What is STORED is always the
 * subscriber's own words; this form exists only so that the same memory,
 * typed twice, is recognised as the same memory.
 *
 * IT IS DELIBERATELY CONSERVATIVE. It touches presentation, never letters:
 *
 *   1. Unicode NFC — the same text typed two ways becomes the same bytes;
 *   2. every Unicode space run collapses to one ASCII space, then trim;
 *   3. the tatweel (ـ, U+0640) is removed — it is a stretching glyph with no
 *      phonetic or semantic value at all;
 *   4. Arabic diacritics (tashkeel, U+064B–U+0652 and U+0670) are removed —
 *      they are marks ON letters, not letters, and «قَهْوَة» and «قهوة» are one
 *      word;
 *   5. ASCII case is folded, so «Coffee» and «coffee» are one word.
 *
 * WHAT IT REFUSES TO DO, and why: it does NOT fold ة→ه, ى→ي, or the hamza
 * family (أ إ آ ؤ ئ → ا/و/ي). Those are DIFFERENT LETTERS, and folding them
 * merges words that are genuinely distinct — «دعا» and «دعى», «سيارة» and
 * «سياره» carry different meanings in different sentences. A normaliser that
 * over-folds silently collapses two real memories into one and loses the
 * second forever; the failure mode of under-folding is merely a duplicate the
 * subscriber can see and forget. Under-folding is the safe direction, so this
 * is where it stops.
 */
final class MemoryText
{
    /**
     * Invisible formatting and direction controls: zero-width space, the bidi
     * marks, embeddings, overrides and isolates, the word joiner and the BOM.
     *
     * They are removed because they are not text: they carry no letter, they
     * make two visually IDENTICAL memories hash differently (a free way past
     * duplicate detection), and a bidi override inside a memory can reorder how
     * the line renders in a prompt. The zero-width NON-joiner and joiner
     * (U+200C/U+200D) are deliberately NOT in this list — they can be
     * orthographic in some scripts, and merging is the direction this code
     * never takes on its own.
     */
    private const INVISIBLE = '/[\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u';

    /** Tashkeel: fathatan…sukun, plus the superscript alef. */
    private const DIACRITICS = '/[\x{064B}-\x{0652}\x{0670}]/u';

    private const TATWEEL = "\u{0640}";

    /** Any Unicode whitespace, including the Arabic-script ones. */
    private const SPACES = '/[\p{Z}\s]+/u';

    public static function normalize(string $value): string
    {
        $value = self::toNfc($value);
        $value = self::strip(self::INVISIBLE, '', $value);
        $value = str_replace(self::TATWEEL, '', $value);
        $value = self::strip(self::DIACRITICS, '', $value);
        $value = self::strip(self::SPACES, ' ', $value);

        // Case folding is ASCII-only on purpose: Arabic has no case, and a
        // locale-aware fold would make the fingerprint depend on the server's
        // locale.
        return strtolower(trim($value));
    }

    /**
     * A `/u` pattern returns NULL on malformed UTF-8, and `(string) null` is the
     * empty string — which would quietly collapse every malformed value onto one
     * normalised form, and therefore onto one fingerprint. Keeping the previous
     * value instead means a malformed memory stays distinct; the service refuses
     * it separately, on its own terms.
     */
    private static function strip(string $pattern, string $replacement, string $value): string
    {
        $result = preg_replace($pattern, $replacement, $value);

        return is_string($result) ? $result : $value;
    }

    /**
     * One memory, rendered as ONE line of a prompt: invisible controls gone and
     * every whitespace run collapsed. This is not cosmetic — a stored newline
     * would let one memory pose as several lines, and a bidi override would let
     * it render in an order it was not written in.
     */
    public static function oneLine(string $value): string
    {
        $value = self::strip(self::INVISIBLE, '', $value);

        return trim(self::strip('/\s+/u', ' ', $value));
    }

    private static function toNfc(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            return $value; // ext-intl is present in CI and in the app image
        }

        $normalised = Normalizer::normalize($value, Normalizer::FORM_C);

        return $normalised === false ? $value : $normalised;
    }
}
