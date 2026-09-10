<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of transcribing ONE inbound voice note.
 *
 * The audio `Message` is the subscriber's message — there is never a second
 * synthetic one — so this status describes how that row's `text_content` came
 * to exist, not a separate entity.
 *
 * `Processing` is the claimed state and the one that matters for safety: a row
 * sits here while a worker owns it under a claim token, and the sweeper decides
 * whether the remaining attempt budget allows one more physical provider
 * request. It is NOT a failure and NOT a confirmed zero-cost state.
 */
enum TranscriptionStatus: string
{
    /** Accepted, not yet claimed by a worker. */
    case Pending = 'pending';

    /** A worker holds the claim. A dispatch may or may not have happened. */
    case Processing = 'processing';

    /** A transcript exists on the message. Terminal, and never re-transcribed. */
    case Transcribed = 'transcribed';

    /** Terminally refused or failed, with a bounded reason. */
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Transcribed || $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار التفريغ',
            self::Processing => 'قيد التفريغ',
            self::Transcribed => 'مُفرَّغ',
            self::Failed => 'فشل التفريغ',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
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
