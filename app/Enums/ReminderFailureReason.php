<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The CLOSED set of reasons a reminder delivery did not (or could not) go out.
 *
 * These are what `reminders.last_error` stores — a bounded operational code,
 * never a provider payload, never an exception message, never anything that
 * could carry the reminder title, a phone number or a message body.
 *
 * `Unknown` is deliberately NOT terminal: it records that a physical dispatch
 * was authorised and Sanad cannot prove the provider accepted or rejected it.
 * It must never be read as a confirmed failure, and never as confirmed zero
 * provider cost.
 */
enum ReminderFailureReason: string
{
    /** The occasion has passed: more than the configured lateness window late. */
    case TooLate = 'too_late';

    /** Outside the free-form window and no approved template is configured. */
    case TemplateRequired = 'template_required';

    /** No single, unambiguous, active account to deliver to. Never guessed. */
    case NoRecipient = 'no_recipient';

    /** The provider positively rejected the message. It was not delivered. */
    case Rejected = 'rejected';

    /** A dispatch was authorised; the outcome is neither proven nor disproven. */
    case Unknown = 'unknown';

    /** The retry budget is spent with no proof of delivery. */
    case AttemptsExhausted = 'attempts_exhausted';

    /** Delivery is switched off, or the channel is not configured to send. */
    case DeliveryDisabled = 'delivery_disabled';

    /** The channel has no delivery path in this build. */
    case ChannelUnsupported = 'channel_unsupported';

    /** Everything else, reported without detail. */
    case Internal = 'internal';

    /**
     * Whether this reason ends the reminder's life. `Unknown` does not: the
     * sweeper decides whether the remaining budget allows one more dispatch.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Unknown;
    }

    public function label(): string
    {
        return match ($this) {
            self::TooLate => 'فات الأوان',
            self::TemplateRequired => 'يحتاج قالبًا معتمَدًا',
            self::NoRecipient => 'لا يوجد مستلِم واضح',
            self::Rejected => 'رفضه المزوّد',
            self::Unknown => 'نتيجة غير معروفة',
            self::AttemptsExhausted => 'نفدت المحاولات',
            self::DeliveryDisabled => 'التسليم معطّل',
            self::ChannelUnsupported => 'القناة غير مدعومة',
            self::Internal => 'خطأ داخلي',
        };
    }

    /**
     * The operator-facing explanation. `Unknown` gets the longest one on
     * purpose: it is the reason most likely to be misread as a failure.
     */
    public function hint(): string
    {
        return match ($this) {
            self::TooLate => 'تجاوز التذكير أقصى تأخّر مقبول، وتسليمه بعدها أسوأ من عدمه.',
            self::TemplateRequired => 'رسالة استباقية خارج نافذة الخدمة بلا قالب معتمَد ومضبوط: فشل مغلق بلا طلب خارجي.',
            self::NoRecipient => 'لا يوجد حساب قناة واحد نشط لا لبس فيه — ولا يُخمَّن المستلِم أبدًا.',
            self::Rejected => 'رفض صريح من المزوّد: لم تُسلَّم الرسالة.',
            self::Unknown => 'أُذِن بمحاولة فعلية وانقطعت المعرفة قبل التسوية. ليست فشلًا مؤكَّدًا، ولا تعني تكلفة صفرًا.',
            self::AttemptsExhausted => 'استُهلكت المحاولتان الفعليّتان بلا إثبات تسليم.',
            self::DeliveryDisabled => 'التسليم مطفأ بالإعداد، أو القناة غير مهيّأة للإرسال.',
            self::ChannelUnsupported => 'لا يوجد مسار تسليم لهذه القناة في هذا الإصدار.',
            self::Internal => 'خطأ داخلي، يُسجَّل بلا تفاصيل.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Options for an admin dropdown: value => label. The filter is validated
     * against this closed set, so an unknown reason can never become a LIKE.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
