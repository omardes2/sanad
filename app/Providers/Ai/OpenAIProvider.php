<?php

declare(strict_types=1);

namespace App\Providers\Ai;

/**
 * OpenAI provider — the platform's primary AI provider.
 *
 * Differences from the generic OpenAI-compatible base are deliberately small:
 * OpenAI caps output with max_completion_tokens (max_tokens is deprecated for
 * current models), and optional organization/project headers scope the request
 * when an account has several. Keys/ids come from config only, never code.
 */
final class OpenAIProvider extends OpenAICompatibleChatProvider
{
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
