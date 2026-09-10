<?php

declare(strict_types=1);

namespace App\Providers\Ai;

use App\Contracts\Ai\SupportsTranscription;
use App\Data\Ai\Health\HealthCapabilities;
use App\Enums\AiOperation;
use App\Providers\Ai\Concerns\TranscribesOpenAICompatibleAudio;

/**
 * OpenAI provider — the platform's primary AI provider.
 *
 * Differences from the generic OpenAI-compatible base are deliberately small:
 * OpenAI caps output with max_completion_tokens (max_tokens is deprecated for
 * current models), and optional organization/project headers scope the request
 * when an account has several. Keys/ids come from config only, never code.
 *
 * CHAT AND TRANSCRIPTION, like every other provider that can serve both. It
 * declares transcription by implementing the capability contract and using the
 * shared audio trait — the same two lines the Groq adapter uses, and the same
 * two lines a third provider would add. Nothing about transcription is special-
 * cased per vendor anywhere above this class: which provider serves a voice
 * note is the router's decision from the model catalog, so an operator can move
 * transcription between OpenAI and Groq by changing catalog data and no code.
 *
 * The transcription MODEL is never declared here and never hard-coded: it comes
 * from the catalogued routable model the router selected.
 */
final class OpenAIProvider extends OpenAICompatibleChatProvider implements SupportsTranscription
{
    use TranscribesOpenAICompatibleAudio;

    public function supports(AiOperation $operation): bool
    {
        return in_array($operation, [AiOperation::Chat, AiOperation::Transcription], true);
    }

    /**
     * OpenAI's `GET /v1/models` is an authenticated, non-billable listing —
     * declared explicitly (Phase C3, decision C), never assumed.
     */
    public function healthCapabilities(): HealthCapabilities
    {
        return new HealthCapabilities(nonBillableAuthProbe: true, authProbePath: '/models');
    }

    protected function maxTokensKey(): string
    {
        return 'max_completion_tokens';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $headers = [];

        if (($organization = $this->configString('organization')) !== '') {
            $headers['OpenAI-Organization'] = $organization;
        }

        if (($project = $this->configString('project')) !== '') {
            $headers['OpenAI-Project'] = $project;
        }

        return $headers;
    }
}
