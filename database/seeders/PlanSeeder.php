<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BillingPeriod;
use App\Enums\PlanFeature;
use App\Enums\UsageDimension;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeds the initial set of plans.
 *
 * ⚠️ NON-PRODUCTION DEFAULTS. Usage limits/features follow the product brief,
 * but PRICES are placeholders (0.00) until final commercial pricing is set from
 * the Plans page — a 0.00 price does NOT mean the tier is free. Idempotent
 * (updateOrCreate by slug), safe to re-run.
 *
 * Limits and features are DATA: each tier declares independent per-dimension
 * limits and per-feature entitlements. Adding a new dimension/feature is a
 * one-line enum addition — no schema change and nothing here is required.
 *
 *   php artisan db:seed --class=Database\\Seeders\\PlanSeeder
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->plans() as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                array_merge($plan, [
                    'description' => $plan['description'] ?? 'باقة سَنَد — الأسعار الحالية تجريبية وغير نهائية.',
                    'price' => 0, // DEMO placeholder — real pricing set from the Plans page.
                    'currency' => config('billing.currency', 'USD'),
                    'billing_period' => BillingPeriod::Monthly->value,
                    'is_active' => true,
                ]),
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        return [
            [
                'name' => 'مجاني', 'slug' => 'free', 'trial_days' => 0,
                'is_default' => true, 'sort_order' => 1,
                'limits' => $this->limits([
                    UsageDimension::AiReply->value => [5, 50],
                    UsageDimension::Reminder->value => [3, 30],
                    UsageDimension::Task->value => [3, 30],
                ]),
                'features' => [
                    PlanFeature::Reminders->value => true,
                    PlanFeature::Tasks->value => true,
                    PlanFeature::Priority->value => 'normal',
                ],
            ],
            [
                'name' => 'أساسي', 'slug' => 'basic', 'trial_days' => 0,
                'is_default' => false, 'sort_order' => 2,
                'limits' => $this->limits([
                    UsageDimension::AiReply->value => [30, 500],
                    UsageDimension::Reminder->value => [30, 500],
                    UsageDimension::Task->value => [30, 500],
                    UsageDimension::VoiceMessage->value => [10, 200],
                    UsageDimension::VoiceMinute->value => [20, 400],
                ]),
                'features' => [
                    PlanFeature::Reminders->value => true,
                    PlanFeature::Tasks->value => true,
                    PlanFeature::ExpenseTracking->value => true,
                    PlanFeature::Memory->value => true,
                    PlanFeature::Voice->value => true,
                    PlanFeature::Priority->value => 'normal',
                ],
            ],
            [
                'name' => 'بلس', 'slug' => 'plus', 'trial_days' => 0,
                'is_default' => false, 'sort_order' => 3,
                'limits' => $this->limits([
                    UsageDimension::AiReply->value => [100, 2000],
                    UsageDimension::Reminder->value => [100, 2000],
                    UsageDimension::Task->value => [100, 2000],
                    UsageDimension::VoiceMessage->value => [50, 1000],
                    UsageDimension::VoiceMinute->value => [100, 2000],
                    UsageDimension::Image->value => [50, 1000],
                    UsageDimension::ToolAction->value => [100, 2000],
                ]),
                'features' => [
                    PlanFeature::Reminders->value => true,
                    PlanFeature::Tasks->value => true,
                    PlanFeature::ExpenseTracking->value => true,
                    PlanFeature::Memory->value => true,
                    PlanFeature::Voice->value => true,
                    PlanFeature::Images->value => true,
                    PlanFeature::Tools->value => true,
                    PlanFeature::Priority->value => 'high',
                ],
            ],
            [
                'name' => 'برو', 'slug' => 'pro', 'trial_days' => 0,
                'is_default' => false, 'sort_order' => 4,
                'limits' => $this->limits([
                    UsageDimension::AiReply->value => [300, 6000],
                    UsageDimension::Reminder->value => [null, null], // unlimited
                    UsageDimension::Task->value => [null, null],
                    UsageDimension::VoiceMessage->value => [200, 4000],
                    UsageDimension::VoiceMinute->value => [400, 8000],
                    UsageDimension::Image->value => [200, 4000],
                    UsageDimension::ToolAction->value => [null, null],
                    UsageDimension::CallMinute->value => [60, 600],
                ]),
                'features' => [
                    PlanFeature::Reminders->value => true,
                    PlanFeature::Tasks->value => true,
                    PlanFeature::ExpenseTracking->value => true,
                    PlanFeature::Memory->value => true,
                    PlanFeature::AdvancedMemory->value => true,
                    PlanFeature::Voice->value => true,
                    PlanFeature::Images->value => true,
                    PlanFeature::Tools->value => true,
                    PlanFeature::Calls->value => true,
                    PlanFeature::Priority->value => 'highest',
                ],
            ],
        ];
    }

    /**
     * Build a limits map: dimension => [daily, monthly] (null = unlimited).
     *
     * @param  array<string, array{0: ?int, 1: ?int}>  $map
     * @return array<string, array{daily: ?int, monthly: ?int, weight: int}>
     */
    private function limits(array $map): array
    {
        $limits = [];

        foreach ($map as $dimension => [$daily, $monthly]) {
            $limits[$dimension] = ['daily' => $daily, 'monthly' => $monthly, 'weight' => 1];
        }

        return $limits;
    }
}
