<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\PlanFeature;
use App\Enums\PlanFeatureType;
use App\Enums\UsageDimension;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'billing_period',
        'trial_days',
        'limits',
        'features',
        'is_active',
        'is_default',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'billing_period' => BillingPeriod::class,
            'trial_days' => 'integer',
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The limit config for a dimension, or null when the plan does not include
     * (does not entitle) that dimension.
     *
     * @return array{daily: ?int, monthly: ?int, weight: int}|null
     */
    public function limitFor(UsageDimension $dimension): ?array
    {
        $limits = $this->limits ?? [];
        $entry = $limits[$dimension->value] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        return [
            'daily' => isset($entry['daily']) ? (int) $entry['daily'] : null,
            'monthly' => isset($entry['monthly']) ? (int) $entry['monthly'] : null,
            'weight' => isset($entry['weight']) ? max(1, (int) $entry['weight']) : 1,
        ];
    }

    /**
     * Raw feature value by string key (escape hatch). Prefer the typed
     * hasFeature()/featureValue() below, which are enum-driven and honour each
     * feature's declared type and default.
     */
    public function feature(string $key, mixed $default = null): mixed
    {
        return ($this->features ?? [])[$key] ?? $default;
    }

    /**
     * Whether a capability is entitled under this plan.
     *
     * Boolean features: truthiness of the stored value.
     * Tier features: entitled when the stored (or default) tier is not the
     * lowest tier — use featureValue() when the exact tier matters.
     */
    public function hasFeature(PlanFeature $feature): bool
    {
        $value = $this->featureValue($feature);

        if ($feature->type() === PlanFeatureType::Tier) {
            $tiers = $feature->tiers();

            return $tiers !== [] && $value !== $tiers[0];
        }

        return (bool) $value;
    }

    /**
     * The stored value for a feature, falling back to the feature's own default
     * when the plan does not specify it — so a NEW feature needs no data backfill.
     */
    public function featureValue(PlanFeature $feature): bool|string
    {
        $value = ($this->features ?? [])[$feature->value] ?? null;

        if ($value === null) {
            return $feature->default();
        }

        return $feature->type() === PlanFeatureType::Tier ? (string) $value : (bool) $value;
    }
}
