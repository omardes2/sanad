<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

use App\Support\Ai\ContextRequest;
use App\Support\Ai\PromptContext;

/**
 * Contributes to the prompt for one inbound message. Contributors run in the
 * order listed in config('ai.context_contributors').
 *
 * This is the core extensibility seam of the AI layer: the persona and the
 * conversation history are contributors today; long-term User Memory and
 * tool/action descriptions become contributors later — added to the config
 * list, never by rewriting the orchestrator.
 */
interface ContextContributor
{
    public function contribute(PromptContext $context, ContextRequest $request): void;
}
