<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Exceptions\Ai\AiException;

/**
 * Capability: chat completion (AiOperation::Chat).
 */
interface SupportsChat extends AiProvider
{
    /**
     * @throws AiException on timeout, rate limit, server, request, or config error
     */
    public function chat(AiRequest $request): AiResponse;
}
