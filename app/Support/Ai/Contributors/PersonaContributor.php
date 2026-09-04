<?php

declare(strict_types=1);

namespace App\Support\Ai\Contributors;

use App\Contracts\Ai\ContextContributor;
use App\Support\Ai\ContextRequest;
use App\Support\Ai\PromptContext;

/**
 * Adds the Sanad persona system prompt (Arabic-first, mirrors the user's
 * language/dialect). Runs first so later contributors (memory, tools) layer
 * on top of a defined personality.
 */
final class PersonaContributor implements ContextContributor
{
    public function contribute(PromptContext $context, ContextRequest $request): void
    {
        $context->addSystem((string) config('ai.persona'));
    }
}
