<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\Billing\UsageDecision;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\PromptTemplate;

/**
 * Produces the subscriber-facing (Arabic) message when a metered capability is
 * denied. Kept as its own layer, separate from enforcement, so a later phase
 * can turn the {upgrade} placeholder into a real signed WhatsApp checkout link
 * without touching the engine.
 */
class UsageLimitResponder
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function message(UsageDecision $decision): string
    {
        if ($decision->limitReached()) {
            $upgrade = (string) ($this->settings->get('billing.upgrade_url') ?? '');
            $template = (string) $this->settings->get('billing.limit_reached_message');

            return trim(PromptTemplate::render($template, ['upgrade' => $upgrade]));
        }

        // Disabled / no-subscription.
        return (string) $this->settings->get('billing.feature_disabled_message');
    }
}
