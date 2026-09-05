<?php

declare(strict_types=1);

namespace App\Providers\Ai;

use App\Data\Ai\Health\HealthCapabilities;

/**
 * Groq provider (OpenAI-compatible Chat Completions API). Kept as an optional /
 * fallback provider: everything it needs lives in the shared OpenAI-compatible
 * base, and its behaviour (max_tokens, Bearer auth, error mapping) is unchanged.
 */
final class GroqChatProvider extends OpenAICompatibleChatProvider
{
    /**
     * Groq's `GET /openai/v1/models` is an authenticated, non-billable
     * listing — declared explicitly (Phase C3, decision C), never assumed.
     */
    public function healthCapabilities(): HealthCapabilities
    {
        return new HealthCapabilities(nonBillableAuthProbe: true, authProbePath: '/models');
    }
}
