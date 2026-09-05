<?php

declare(strict_types=1);

namespace App\Support\Ai\Contributors;

use App\Contracts\Ai\ContextContributor;
use App\Services\Settings\SettingsRepository;
use App\Support\Ai\ContextRequest;
use App\Support\Ai\PromptContext;
use App\Support\Settings\PromptTemplate;
use Carbon\CarbonImmutable;

/**
 * Adds the Sanad persona system prompt (Arabic-first, mirrors the user's
 * language/dialect), followed by lightweight, always-safe context: the current
 * date/time in the user's timezone so replies can reason about "today",
 * "tomorrow" and relative times. Runs first so later contributors (memory,
 * tools) layer on top of a defined personality.
 *
 * Since Phase C1 both texts come from the Settings Registry (ai.persona and
 * prompts.temporal_context): editable from Sanad Admin, read at prompt-build
 * time, defaults identical to the previous hard-coded values.
 */
final class PersonaContributor implements ContextContributor
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function contribute(PromptContext $context, ContextRequest $request): void
    {
        $context->addSystem((string) $this->settings->get('ai.persona'));
        $context->addSystem($this->temporalContext($request));
    }

    private function temporalContext(ContextRequest $request): string
    {
        $timezone = $this->timezone($request);
        $now = CarbonImmutable::now($timezone);

        // Keep it factual and compact — the model uses this to interpret times.
        // Placeholders are substituted by strtr() only (no template engine).
        return PromptTemplate::render((string) $this->settings->get('prompts.temporal_context'), [
            'timezone' => $timezone,
            'now' => $now->translatedFormat('l، j F Y - H:i'),
        ]);
    }

    private function timezone(ContextRequest $request): string
    {
        $timezone = $request->user->timezone ?? null;

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('sanad.default_user_timezone', 'Asia/Hebron');
    }
}
