<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Exceptions\Ai\AiException;

/**
 * A single AI backend (Groq, Gemini, Ollama, ...). Implementations own their
 * own model and endpoint; the orchestration layer depends only on this
 * contract, so no application code is coupled to any specific provider.
 *
 * Implementations MUST translate transport/HTTP failures into the typed
 * App\Exceptions\Ai\* hierarchy (never leak a raw body, key, or user content).
 */
interface AiProvider
{
    /**
     * @throws AiException on timeout, rate limit, server, request, or config error
     */
    public function chat(AiRequest $request): AiResponse;

    /**
     * Short provider key (e.g. "groq") for safe logging.
     */
    public function name(): string;
}
