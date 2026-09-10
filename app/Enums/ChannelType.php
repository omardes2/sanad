<?php

declare(strict_types=1);

namespace App\Enums;

enum ChannelType: string
{
    case WhatsApp = 'whatsapp';
    case Web = 'web';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'واتساب',
            self::Web => 'الويب',
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
