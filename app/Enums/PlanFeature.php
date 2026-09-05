<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A non-metered entitlement (a capability switch), stored in a plan's JSON
 * `features`. This is the boolean/tiered counterpart to UsageDimension: where a
 * dimension answers "how many are allowed?", a feature answers "is this capability
 * available at all, and at which tier?".
 *
 * Adding a NEW feature is a one-line addition here — no schema change, no redesign
 * of plans/subscriptions, and the admin plan editor renders it automatically
 * because it iterates over PlanFeature::cases().
 *
 * Most features are booleans; a few are tiered (a small ordered scale). type()
 * tells the UI and the domain layer how to treat each one.
 */
enum PlanFeature: string
{
    case ExpenseTracking = 'expense_tracking';
    case Memory = 'memory';
    case AdvancedMemory = 'advanced_memory';
    case Tools = 'tools';
    case Voice = 'voice';
    case Images = 'images';
    case Reminders = 'reminders';
    case Tasks = 'tasks';
    case Calls = 'calls';
    case Priority = 'priority';

    public function label(): string
    {
        return match ($this) {
            self::ExpenseTracking => 'تتبّع المصروفات',
            self::Memory => 'الذاكرة',
            self::AdvancedMemory => 'الذاكرة المتقدّمة',
            self::Tools => 'الأدوات',
            self::Voice => 'الرسائل الصوتية',
            self::Images => 'الصور',
            self::Reminders => 'التذكيرات',
            self::Tasks => 'المهام',
            self::Calls => 'المكالمات',
            self::Priority => 'الأولوية',
        };
    }

    /**
     * How the feature is valued: a simple on/off switch, or an ordered tier.
     */
    public function type(): PlanFeatureType
    {
        return match ($this) {
            self::Priority => PlanFeatureType::Tier,
            default => PlanFeatureType::Boolean,
        };
    }

    /**
     * The value a plan gets when it does not specify this feature.
     */
    public function default(): bool|string
    {
        return match ($this->type()) {
            PlanFeatureType::Tier => $this->tiers()[0],
            PlanFeatureType::Boolean => false,
        };
    }

    /**
     * Ordered tier values for a Tier feature (lowest → highest); empty otherwise.
     *
     * @return list<string>
     */
    public function tiers(): array
    {
        return match ($this) {
            self::Priority => ['normal', 'high', 'highest'],
            default => [],
        };
    }
}
