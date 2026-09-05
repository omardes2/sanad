<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

use App\Enums\AiOperation;

/**
 * A single AI backend (OpenAI, Groq, ...) as seen by the platform: a named
 * processor that declares which operations it supports. The concrete work is
 * exposed through capability contracts (SupportsChat, SupportsTools, ...), so a
 * provider implements only what it can actually do and the router never guesses.
 *
 * Providers are PROCESSORS only — Sanad's database is the source of truth for
 * every subscriber fact, memory, and result. Implementations MUST translate
 * transport/HTTP failures into the typed App\Exceptions\Ai\* hierarchy and never
 * leak a raw body, key, or user content.
 */
interface AiProvider
{
    /**
     * Short provider key (e.g. "openai", "groq") for routing, catalog and safe logging.
     */
    public function name(): string;

    public function supports(AiOperation $operation): bool;

    /**
     * Whether the provider has what it needs (credentials, endpoint) to be
     * routed to. A misconfigured provider is skipped by the router, never called.
     */
    public function isConfigured(): bool;
}
