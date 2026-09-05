<?php

declare(strict_types=1);

namespace App\Providers\Ai;

/**
 * Groq provider (OpenAI-compatible Chat Completions API). Kept as an optional /
 * fallback provider: everything it needs lives in the shared OpenAI-compatible
 * base, and its behaviour (max_tokens, Bearer auth, error mapping) is unchanged.
 */
final class GroqChatProvider extends OpenAICompatibleChatProvider {}
