<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

/**
 * Capability: tool (function) calling within chat.
 *
 * A provider implementing this honours AiRequest::$tools (a list of
 * provider-agnostic AiToolDefinition) and reports the model's requested calls
 * as AiResponse::$toolCalls (provider-agnostic AiToolCall). The provider only
 * TRANSLATES between its wire format and Sanad's internal DTOs; executing a
 * tool is the platform's job (ToolRunner, a later phase), so the same tool
 * definitions work unchanged across OpenAI, Groq, or any future provider.
 */
interface SupportsTools extends SupportsChat {}
