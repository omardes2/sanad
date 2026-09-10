<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

use App\Data\Ai\TranscriptionRequest;
use App\Data\Ai\TranscriptionResult;
use App\Exceptions\Ai\AiException;

/**
 * Capability: speech-to-text (AiOperation::Transcription).
 *
 * The contract is PROVIDER-NEUTRAL by construction: it takes a local audio
 * path, a MIME type and the ROUTED model, and returns text. Nothing in
 * TranscriptionRequest or TranscriptionResult names a vendor, and nothing here
 * lets a caller pass a vendor-specific knob — a second provider is one adapter,
 * not a widened interface.
 *
 * A transcription is an EXTERNAL PAID READ whose outcome may be UNKNOWN: a
 * timeout means the provider may have processed and charged for the audio while
 * we cannot prove it either way. Implementations therefore MUST map transport
 * and HTTP failures onto the typed App\Exceptions\Ai\* hierarchy — retryable
 * (timeout, 429, 5xx) versus permanent (4xx, empty answer) — because the caller
 * decides "unknown" versus "failed" from that distinction, never from a string.
 *
 * As everywhere else in this hierarchy: never leak a raw body, a key, the local
 * path, or the subscriber's words into an exception message or a log line.
 */
interface SupportsTranscription extends AiProvider
{
    /**
     * @throws AiException on timeout, rate limit, server, request, or config error
     */
    public function transcribe(TranscriptionRequest $request): TranscriptionResult;
}
