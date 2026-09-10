<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of durable personal memory Sanad keeps (Phase G) — a CLOSED set,
 * declared in code, matching exactly what the V1 launch scope names.
 *
 * The `memories.category` column is a plain string (ADR-0013: enums live in
 * PHP, not in the database), so adding a kind is a case here and needs no
 * migration. A value outside this list is refused by the tool schema before
 * any write, and by the domain service if it is ever reached another way.
 */
enum MemoryCategory: string
{
    /** What the subscriber likes or wants: «بفضّل التذكير مساءً». */
    case Preference = 'preference';

    /** What they habitually do: «بمشي كل صباح». */
    case Habit = 'habit';

    /** A stable fact about them: «بشتغل مهندس». */
    case Fact = 'fact';

    /** Something they are working towards: «بدي أوفّر لسيارة». */
    case Goal = 'goal';

    /** A person who matters to them, within the privacy policy. */
    case Relationship = 'relationship';

    /** How they want to be spoken to: dialect, tone, length. */
    case Style = 'style';

    /** @return list<string> the closed option list for the tool schemas */
    public static function options(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Preference => 'تفضيل',
            self::Habit => 'عادة',
            self::Fact => 'معلومة',
            self::Goal => 'هدف',
            self::Relationship => 'علاقة',
            self::Style => 'أسلوب',
        };
    }
}
