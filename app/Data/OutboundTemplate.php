<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A pre-approved provider template to send instead of free-form text.
 *
 * Templates are the platform's permitted mechanism for a PROACTIVE message —
 * one sent outside the window in which free-form replies are allowed. The name
 * and language come from configuration and are never invented in code; the
 * parameters are positional body substitutions.
 */
final readonly class OutboundTemplate
{
    /**
     * @param  list<string>  $parameters  positional body parameters, in order
     */
    public function __construct(
        public string $name,
        public string $language,
        public array $parameters = [],
    ) {}
}
