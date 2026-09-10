<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The CLOSED set of reasons a voice note did not become a transcript.
 *
 * These are LANGUAGE-NEUTRAL CODES. Sanad is multilingual, so the code decides
 * *what* happened and the translation layer decides *how it is said* — the
 * subscriber's reply comes from `lang/<locale>/voice.php`, keyed by these
 * values, and never from a hard-coded Arabic string in domain logic.
 *
 * No provider exception text ever reaches a subscriber: every external failure
 * collapses into one of these codes before anything is rendered.
 *
 * `Unknown` is deliberately NOT terminal, and the distinction is the same one
 * reminder delivery draws: a dispatch was authorised and Sanad cannot prove the
 * provider did or did not process (and charge for) it. It must never be read as
 * a confirmed failure, and never as confirmed zero provider cost.
 */
enum TranscriptionFailureReason: string
{
    /** The subscriber's plan does not include voice. No paid work was done. */
    case VoiceNotInPlan = 'voice_not_in_plan';

    /** Not a supported voice-note payload (wrong MIME, or not a voice note). */
    case UnsupportedAudio = 'unsupported_audio';

    /** Larger than the configured byte ceiling. */
    case AudioTooLarge = 'audio_too_large';

    /** Longer than the configured duration ceiling. */
    case AudioTooLong = 'audio_too_long';

    /** The media metadata or the binary could not be fetched. */
    case MediaDownloadFailed = 'media_download_failed';

    /** The provider's media reference is no longer resolvable. */
    case MediaExpired = 'media_expired';

    /** No routable, configured transcription provider/model exists. */
    case TranscriptionNotConfigured = 'transcription_not_configured';

    /** The provider positively failed the request. Nothing was produced. */
    case TranscriptionFailed = 'transcription_failed';

    /** A request was authorised; the outcome is neither proven nor disproven. */
    case TranscriptionUnknown = 'transcription_unknown';

    /** The provider answered, and the transcript was empty. */
    case TranscriptEmpty = 'transcript_empty';

    /** Everything else, reported without detail. */
    case Internal = 'internal';

    /**
     * Whether this reason ends the voice note's life. `TranscriptionUnknown`
     * does not: the sweeper decides whether the remaining budget allows one
     * more physical attempt.
     */
    public function isTerminal(): bool
    {
        return $this !== self::TranscriptionUnknown;
    }

    /**
     * Did this reason occur BEFORE any paid provider request?
     *
     * Used to assert, in tests and in the dispatcher, that a refusal on the
     * cheap side of the boundary cannot have cost anything.
     */
    public function precedesPaidWork(): bool
    {
        return match ($this) {
            self::VoiceNotInPlan,
            self::UnsupportedAudio,
            self::AudioTooLarge,
            self::AudioTooLong,
            self::MediaDownloadFailed,
            self::MediaExpired,
            self::TranscriptionNotConfigured => true,
            default => false,
        };
    }

    /** The translation key for the subscriber-facing reply. */
    public function translationKey(): string
    {
        return 'voice.failure.'.$this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** Operator-facing label. NOT the subscriber reply — that is localized. */
    public function label(): string
    {
        return match ($this) {
            self::VoiceNotInPlan => 'الباقة لا تشمل الصوت',
            self::UnsupportedAudio => 'صيغة غير مدعومة',
            self::AudioTooLarge => 'الملف أكبر من الحدّ',
            self::AudioTooLong => 'المدّة أطول من الحدّ',
            self::MediaDownloadFailed => 'تعذّر تنزيل الوسيط',
            self::MediaExpired => 'انتهت صلاحية الوسيط',
            self::TranscriptionNotConfigured => 'لا يوجد مزوّد تفريغ مضبوط',
            self::TranscriptionFailed => 'فشل المزوّد',
            self::TranscriptionUnknown => 'نتيجة غير معروفة',
            self::TranscriptEmpty => 'نصّ فارغ',
            self::Internal => 'خطأ داخلي',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
