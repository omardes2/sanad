<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BillingPeriod;
use App\Enums\UsageDimension;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeds the initial set of plans.
 *
 * ⚠️ NON-PRODUCTION DEFAULTS. Usage limits follow the product brief, but PRICES
 * are placeholders only — final commercial pricing is set by the admin in the
 * Plans page. Idempotent (updateOrCreate by slug), safe to re-run.
 *
 *   php artisan db:seed --class=Database\\Seeders\\PlanSeeder
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $ai = UsageDimension::AiReply->value;

        $plans = [
            [
                'name' => 'مجاني', 'slug' => 'free', 'price' => 0,
                'trial_days' => 0, 'is_default' => true, 'sort_order' => 1,
                'limits' => [$ai => ['daily' => 5, 'monthly' => 50, 'weight' => 1]],
            ],
            [
                'name' => 'أساسي', 'slug' => 'basic', 'price' => 0, // DEMO price placeholder
                'trial_days' => 0, 'is_default' => false, 'sort_order' => 2,
                'limits' => [$ai => ['daily' => 30, 'monthly' => 500, 'weight' => 1]],
            ],
            [
                'name' => 'بلس', 'slug' => 'plus', 'price' => 0, // DEMO price placeholder
                'trial_days' => 0, 'is_default' => false, 'sort_order' => 3,
                'limits' => [$ai => ['daily' => 100, 'monthly' => 2000, 'weight' => 1]],
            ],
            [
                'name' => 'برو', 'slug' => 'pro', 'price' => 0, // DEMO price placeholder
                'trial_days' => 0, 'is_default' => false, 'sort_order' => 4,
                'limits' => [$ai => ['daily' => 300, 'monthly' => 6000, 'weight' => 1]],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                array_merge($plan, [
                    'description' => 'باقة سَنَد — الأسعار الحالية تجريبية وغير نهائية.',
                    'currency' => config('billing.currency', 'USD'),
                    'billing_period' => BillingPeriod::Monthly->value,
                    'features' => [],
                    'is_active' => true,
                ]),
            );
        }
    }
}
