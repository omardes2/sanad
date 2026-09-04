<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\Billing\UsageDecision;

/**
 * Produces the subscriber-facing (Arabic) message when a metered capability is
 * denied. Kept as its own layer, separate from enforcement, so a later phase
 * can turn the {upgrade} placeholder into a real signed WhatsApp checkout link
 * without touching the engine.
 */
class UsageLimitResponder
{
    public function message(UsageDecision $decision): string
    {
        if ($decision->limitReached()) {
            $upgrade = (string) (config('billing.upgrade_url') ?? '');
            $template = (string) config('billing.limit_reached_message');

            return trim(str_replace('{upgrade}', $upgrade, $template));
        }

        // Disabled / no-subscription.
        return (string) config('billing.feature_disabled_message');
    }
}
