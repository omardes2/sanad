<?php

declare(strict_types=1);

namespace App\Data\Ai;

use App\Data\Ai\Catalog\ModelSpec;
use SensitiveParameter;

/**
 * One transcription request, in PROVIDER-NEUTRAL terms.
 *
 * Deliberately carries NO VENDOR FIELD at all — no `response_format`, no
 * `temperature`, no vendor-shaped names. A further provider must be addable by
 * writing one adapter, not by widening this contract, so anything only one
 * vendor understands belongs inside that vendor's adapter.
 *
 * The MODEL is not chosen here and is never hard-coded: it arrives as the
 * routed `ModelSpec` the router selected for `AiOperation::Transcription`.
 */
final readonly class TranscriptionRequest
{
    public function __construct(
        /** Absolute path to a readable local audio file. Ephemeral by contract. */
        #[SensitiveParameter]
        public string $path,
        public string $mimeType,
        /** The routed model. Never a literal in application code. */
        public ModelSpec $spec,
        /** BCP-47-ish hint from the subscriber's locale, or null to let the provider decide. */
        public ?string $languageHint = null,
        public ?int $durationMs = null,
        /** Seconds. Upload plus decode, so comfortably longer than a chat turn. */
        public int $timeout = 120,
    ) {}

    /** The file name presented to the provider. Never the local temp path. */
    public function uploadName(): string
    {
        return 'voice-note.'.match (true) {
            str_contains($this->mimeType, 'ogg'), str_contains($this->mimeType, 'opus') => 'ogg',
            str_contains($this->mimeType, 'mpeg') => 'mp3',
            str_contains($this->mimeType, 'wav') => 'wav',
            default => 'bin',
        };
    }
}
