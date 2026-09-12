<?php

declare(strict_types=1);

namespace App\Support\FollowUps;

use App\Enums\MessageDirection;
use App\Models\Message;

/**
 * Did the SUBSCRIBER actually say WHEN? (Phase H3)
 *
 * WHY THIS IS A SEPARATE GATE FROM INTENT. «تابع معي» is authority to follow up;
 * it is NOT authority to pick a time. Without this check the model would supply
 * one — tomorrow, in 24 hours, "after the expected outcome" — and the subscriber
 * would receive a proactive message at an hour they never chose, from a default
 * nobody approved. So V1 refuses to create a follow-up whose timing was invented:
 * if the conversation carries no definite future moment, Sanad ASKS.
 *
 * WHAT IS AND IS NOT VERIFIED HERE. The model still does the interpreting — it
 * turns «بعد بكرا الصبح» into an absolute UTC instant, which is exactly the kind
 * of language work a model should do. What the server verifies is the narrower,
 * checkable fact that a temporal expression was PRESENT in the subscriber's own
 * words. That is enough to stop an invented schedule, and it does not pretend to
 * re-parse Arabic time language in PHP.
 *
 * THE LOOKBACK EXISTS BECAUSE PEOPLE SPEAK IN TWO MESSAGES. «بكرا بدفع الفاتورة»
 * then «تابع معي» is one request, and refusing it would be a false negative the
 * subscriber experiences as Sanad not listening. So a small, bounded number of
 * the subscriber's own recent inbound messages IN THE SAME CONVERSATION count as
 * context — stored rows, never model recollection.
 *
 * Failing this gate is not an error: it is the signal to ask the subscriber when
 * they want to be followed up with, and to create nothing until they say.
 */
final class FollowUpTimeEvidence
{
    /**
     * How many of the subscriber's previous inbound messages in this conversation
     * may supply the time. Deliberately small: the further back it reaches, the
     * more likely it is picking up a time that belonged to something else.
     */
    public const LOOKBACK = 3;

    /**
     * Arabic temporal expressions, as stems so that attached particles and
     * suffixes («بكرا»، «الجمعة»، «بالمسا») still match.
     *
     * @var list<string>
     */
    private const STEMS = [
        // Relative days
        'بكرا',
        'بكره',
        'غدا',
        'غدًا',
        'بعد بكرا',
        'بعد بكره',
        'اليوم',
        'الليلة',
        'الليله',
        'هالليلة',
        // Relative weeks and months
        'الاسبوع الجاي',
        'الأسبوع الجاي',
        'الاسبوع القادم',
        'الأسبوع القادم',
        'الشهر الجاي',
        'الشهر القادم',
        'نهاية الاسبوع',
        'نهاية الأسبوع',
        'اول الشهر',
        'أول الشهر',
        'اخر الشهر',
        'آخر الشهر',
        // Weekday names
        'السبت',
        'الاحد',
        'الأحد',
        'الاثنين',
        'الإثنين',
        'التنين',
        'الثلاثاء',
        'الثلاثا',
        'الاربعاء',
        'الأربعاء',
        'الاربعا',
        'الخميس',
        'الجمعة',
        'الجمعه',
        // Times of day and clock references
        'الساعة',
        'الساعه',
        'الصبح',
        'صباح',
        'الظهر',
        'بعد الظهر',
        'العصر',
        'المغرب',
        'المسا',
        'المساء',
        'بالليل',
        // Explicit durations
        'بعد ساعة',
        'بعد ساعتين',
        'بعد يوم',
        'بعد يومين',
        'بعد اسبوع',
        'بعد أسبوع',
        'بعد شهر',
        // English
        'tomorrow',
        'tonight',
        'today',
        'next week',
        'next month',
        'this evening',
        'this afternoon',
        'end of the week',
        'end of the month',
        'in an hour',
        'in two hours',
        'in a day',
        'in two days',
        'in a week',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    /**
     * Patterns for the cases a literal list cannot hold: any digit (Arabic-Indic
     * included, since a subscriber may type «٩»), and the English clock suffixes
     * as whole words so `am` does not match inside `exam`.
     *
     * @var list<string>
     */
    private const PATTERNS = [
        '/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u',
        '/(?<![\p{L}\p{N}])(am|pm|a\.m|p\.m)(?![\p{L}\p{N}])/u',
    ];

    /**
     * Is there a definite-enough future moment in the subscriber's own words —
     * in this message, or in a bounded window of their recent ones?
     */
    public static function present(Message $message): bool
    {
        if ($message->direction !== MessageDirection::Inbound) {
            return false;
        }

        if (self::inText((string) $message->text_content)) {
            return true;
        }

        foreach (self::recent($message) as $text) {
            if (self::inText($text)) {
                return true;
            }
        }

        return false;
    }

    /** The same decision over raw text — used by the tests and by nothing else. */
    public static function inText(string $text): bool
    {
        return FollowUpPhrases::anyStem($text, self::STEMS)
            || FollowUpPhrases::anyPattern($text, self::PATTERNS);
    }

    /**
     * The subscriber's own previous inbound messages in the SAME conversation.
     *
     * Inbound only: Sanad's own suggestion of a time is not the subscriber
     * supplying one, which is the entire point of this gate.
     *
     * @return list<string>
     */
    private static function recent(Message $message): array
    {
        if ($message->conversation_id === null) {
            return [];
        }

        return Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $message->user_id)
            ->where('direction', MessageDirection::Inbound->value)
            ->where('id', '<', $message->getKey())
            ->orderByDesc('id')
            ->limit(self::LOOKBACK)
            ->pluck('text_content')
            ->map(static fn ($text): string => (string) $text)
            ->all();
    }
}
