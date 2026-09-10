<?php

declare(strict_types=1);

namespace App\Data\Ai;

/**
 * What a transcription provider returned.
 *
 * `durationMs` is whatever the PROVIDER reported, which is not the same fact as
 * the duration Sanad measured from the bytes before dispatching — the measured
 * one is what the ceiling is enforced against, and this one is only recorded.
 * Keeping them distinct matters: a provider's number arrives after we have
 * already paid, so it can never be a gate.
 */
final readonly class TranscriptionResult
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public ?string $language = null,
        public ?int $durationMs = null,
        /** @var array<string, mixed> diagnostics only — never rendered, never logged raw. */
        public array $metadata = [],
    ) {}

    /** A provider that answered with nothing produced no transcript. */
    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }
}
