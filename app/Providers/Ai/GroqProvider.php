<?php

declare(strict_types=1);

namespace App\Providers\Ai;

use App\Contracts\Ai\SupportsTranscription;
use App\Data\Ai\Health\HealthCapabilities;
use App\Enums\AiOperation;
use App\Providers\Ai\Concerns\TranscribesOpenAICompatibleAudio;

/**
 * Groq provider: OpenAI-compatible Chat Completions AND OpenAI-compatible audio
 * transcription, both against the same base URL and the same credential.
 *
 * ONE OF SEVERAL transcription providers, not the transcription provider. It
 * declares the capability in exactly the same two lines OpenAIProvider does —
 * the contract plus the shared audio trait — and which of them serves a given
 * voice note is the router's decision from the model catalog. So transcription
 * moves between providers by changing catalog data, never code, and nothing
 * above this class names a vendor at all.
 *
 * The transcription MODEL is not declared here and never hard-coded: it comes
 * from the catalogued routable model the router selected. An operator who has
 * catalogued no transcription-capable model gets no route, and the voice path
 * refuses with a bounded reason rather than inventing one.
 */
final class GroqProvider extends OpenAICompatibleChatProvider implements SupportsTranscription
{
    use TranscribesOpenAICompatibleAudio;

    public function supports(AiOperation $operation): bool
    {
        return in_array($operation, [AiOperation::Chat, AiOperation::Transcription], true);
    }

    /**
     * Groq's `GET /openai/v1/models` is an authenticated, non-billable
     * listing — declared explicitly (Phase C3, decision C), never assumed.
     */
    public function healthCapabilities(): HealthCapabilities
    {
        return new HealthCapabilities(nonBillableAuthProbe: true, authProbePath: '/models');
    }
}
