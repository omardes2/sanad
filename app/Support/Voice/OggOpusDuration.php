<?php

declare(strict_types=1);

namespace App\Support\Voice;

/**
 * How long an Ogg/Opus voice note is, read from the bytes themselves.
 *
 * WHY THIS EXISTS. A duration ceiling has to be enforced BEFORE the paid
 * transcription request, and nothing in this project's runtime guarantees
 * `ffprobe`: it is not in `composer.json`, there is no Dockerfile, and
 * `docker-compose.yml` provisions only PostgreSQL and Redis with the app on the
 * host. Adding an undocumented OS dependency to enforce one limit is a worse
 * trade than reading the container ourselves — and a byte ceiling is a PROXY
 * for length, never a duration limit, so it cannot stand in for one.
 *
 * HOW IT WORKS. An Ogg stream is a sequence of pages. Each page header starts
 * with the capture pattern "OggS" and carries a 64-bit little-endian GRANULE
 * POSITION at byte offset 6. For an Opus stream the granule position counts
 * samples at a fixed 48 kHz regardless of the real sample rate, so the LAST
 * page's granule, minus the encoder's pre-skip from the identification header,
 * divided by 48000, is the playable duration.
 *
 * WHAT IT REFUSES TO GUESS. Anything that is not a well-formed Ogg stream, and
 * any stream whose last page carries no usable granule, returns null — the
 * caller then refuses the voice note rather than inventing a length. Under-
 * reporting a duration would let a long file through the gate we built the gate
 * for, so "unknown" is the only safe answer when the bytes do not say.
 *
 * SCOPE. WhatsApp voice notes are Ogg/Opus, and `config('voice.allowed_mime_types')`
 * admits exactly that — the allowlist and this reader are two statements of the
 * same V1 scope. Widening one without the other would leave a duration limit
 * that silently does not apply.
 */
final class OggOpusDuration
{
    /** Opus granule positions are always counted at 48 kHz, by specification. */
    private const OPUS_SAMPLE_RATE = 48000;

    private const CAPTURE_PATTERN = 'OggS';

    /** The tail we scan for the final page. Ogg pages are at most ~65 KB. */
    private const TAIL_BYTES = 262144;

    /** A page header is 27 bytes before its segment table. */
    private const HEADER_BYTES = 27;

    /**
     * Duration in MILLISECONDS, or null when the bytes do not say.
     *
     * @param  string  $path  an absolute path to a readable local file
     */
    public static function milliseconds(string $path): ?int
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $size = filesize($path);

        if ($size === false || $size < self::HEADER_BYTES) {
            return null;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $head = fread($handle, 65536);

            if (! is_string($head) || ! str_starts_with($head, self::CAPTURE_PATTERN)) {
                return null;    // Not an Ogg stream at all.
            }

            $preSkip = self::preSkip($head);

            // Read only the tail: the final page is near the end by definition.
            $tailFrom = max(0, $size - self::TAIL_BYTES);

            if (fseek($handle, $tailFrom) !== 0) {
                return null;
            }

            $tail = stream_get_contents($handle);

            if (! is_string($tail) || $tail === '') {
                return null;
            }

            $granule = self::lastGranule($tail);

            if ($granule === null || $granule <= 0) {
                return null;
            }

            // The pre-skip is encoder priming that is not playable audio.
            $samples = $granule - $preSkip;

            if ($samples <= 0) {
                return null;
            }

            return (int) round($samples * 1000 / self::OPUS_SAMPLE_RATE);
        } finally {
            fclose($handle);
        }
    }

    /** Convenience for callers that reason in whole seconds. */
    public static function seconds(string $path): ?float
    {
        $ms = self::milliseconds($path);

        return $ms === null ? null : $ms / 1000;
    }

    /**
     * The encoder pre-skip from the OpusHead identification header, in 48 kHz
     * samples. Absent or unreadable ⇒ 0, which only ever makes the measured
     * duration slightly LONGER, so the ceiling stays conservative.
     */
    private static function preSkip(string $head): int
    {
        $at = strpos($head, 'OpusHead');

        if ($at === false || strlen($head) < $at + 12) {
            return 0;
        }

        // OpusHead layout: magic(8) version(1) channels(1) pre-skip(2 LE).
        $unpacked = unpack('v', substr($head, $at + 10, 2));

        return is_array($unpacked) ? (int) ($unpacked[1] ?? 0) : 0;
    }

    /**
     * The granule position of the LAST complete page in the buffer.
     *
     * Scans forward over every capture pattern rather than assuming the final
     * page sits at a fixed offset: a page's length depends on its segment table,
     * so the only honest way to find the last one is to walk them.
     */
    private static function lastGranule(string $buffer): ?int
    {
        $granule = null;
        $offset = 0;
        $length = strlen($buffer);

        while (true) {
            $at = strpos($buffer, self::CAPTURE_PATTERN, $offset);

            if ($at === false || $at + self::HEADER_BYTES > $length) {
                break;
            }

            $segments = ord($buffer[$at + 26]);

            if ($at + self::HEADER_BYTES + $segments > $length) {
                break;      // A truncated final page: not usable.
            }

            $value = self::granuleAt($buffer, $at);

            // 0xFFFFFFFFFFFFFFFF means "no packet finishes on this page".
            if ($value !== null && $value >= 0) {
                $granule = $value;
            }

            // Skip the whole page: header + segment table + payload.
            $payload = 0;

            for ($i = 0; $i < $segments; $i++) {
                $payload += ord($buffer[$at + self::HEADER_BYTES + $i]);
            }

            $offset = $at + self::HEADER_BYTES + $segments + $payload;

            if ($offset <= $at) {
                break;      // Defensive: never loop on a malformed page.
            }
        }

        return $granule;
    }

    /**
     * The 64-bit little-endian granule at a page start, or null when it is the
     * "no packet ends here" sentinel or would overflow a PHP int.
     */
    private static function granuleAt(string $buffer, int $at): ?int
    {
        $bytes = substr($buffer, $at + 6, 8);

        if (strlen($bytes) !== 8) {
            return null;
        }

        if ($bytes === "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF") {
            return null;
        }

        $low = unpack('V', substr($bytes, 0, 4));
        $high = unpack('V', substr($bytes, 4, 4));

        if (! is_array($low) || ! is_array($high)) {
            return null;
        }

        $lowValue = (int) $low[1];
        $highValue = (int) $high[1];

        // A voice note whose granule needs more than 63 bits is not a voice
        // note; refuse rather than wrap into a negative number.
        if ($highValue > 0x7FFFFFFF) {
            return null;
        }

        return ($highValue << 32) | $lowValue;
    }
}
