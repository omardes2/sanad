<?php

declare(strict_types=1);

namespace App\Data\Ai;

/**
 * Chat roles in a provider-agnostic conversation. Providers map these to their
 * own wire format (OpenAI-compatible providers use the same three strings).
 */
enum AiRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
