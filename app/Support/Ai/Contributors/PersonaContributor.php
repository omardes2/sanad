<?php

declare(strict_types=1);

namespace App\Support\Ai\Contributors;

use App\Contracts\Ai\ContextContributor;
use App\Support\Ai\ContextRequest;
use App\Support\Ai\PromptContext;
use Carbon\CarbonImmutable;

/**
 * Adds the Sanad persona system prompt (Arabic-first, mirrors the user's
 * language/dialect), followed by lightweight, always-safe context: the current
 * date/time in the user's timezone so replies can reason about "today",
 * "tomorrow" and relative times. Runs first so later contributors (memory,
 * tools) layer on top of a defined personality.
 */
final class PersonaContributor implements ContextContributor
{
    public function contribute(PromptContext $context, ContextRequest $request): void
    {
        $context->addSystem((string) config('ai.persona'));
        $context->addSystem($this->temporalContext($request));
    }

    private function temporalContext(ContextRequest $request): string
    {
        $timezone = $this->timezone($request);
        $now = CarbonImmutable::now($timezone);

        // Keep it factual and compact — the model uses this to interpret times.
        return sprintf(
            'التاريخ والوقت الآن بتوقيت المستخدم (%s): %s. استخدمه عند فهم كلمات مثل «اليوم» و«غدًا» و«بعد ساعة». وقت النظام يُخزَّن بـUTC.',
            $timezone,
            $now->translatedFormat('l، j F Y - H:i'),
        );
    }

    private function timezone(ContextRequest $request): string
    {
        $timezone = $request->user->timezone ?? null;

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('sanad.default_user_timezone', 'Asia/Hebron');
    }
}
