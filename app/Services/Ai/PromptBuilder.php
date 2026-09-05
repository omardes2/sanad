<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\Ai\ContextContributor;
use App\Data\Ai\AiMessage;
use App\Data\Ai\AiRequest;
use App\Services\Settings\SettingsRepository;
use App\Support\Ai\ContextRequest;
use App\Support\Ai\PromptContext;
use Illuminate\Contracts\Container\Container;

/**
 * Turns a ContextRequest into a provider-agnostic AiRequest by running the
 * configured context contributors in order, then assembling the system prompt
 * and chat turns. Generation parameters come from the Settings Registry
 * (database override, else config('ai') default) — never from a specific
 * provider — so the builder stays provider-agnostic.
 */
class PromptBuilder
{
    public function __construct(
        private readonly Container $container,
        private readonly SettingsRepository $settings,
    ) {}

    public function build(ContextRequest $request): AiRequest
    {
        $context = new PromptContext;

        foreach ($this->contributors() as $contributor) {
            $contributor->contribute($context, $request);
        }

        $messages = [];

        if (($system = $context->systemPrompt()) !== null) {
            $messages[] = AiMessage::system($system);
        }

        foreach ($context->messages() as $message) {
            $messages[] = $message;
        }

        return new AiRequest(
            messages: $messages,
            temperature: (float) $this->settings->get('ai.temperature'),
            maxOutputTokens: (int) $this->settings->get('ai.max_output_tokens'),
            timeout: (int) $this->settings->get('ai.timeout'),
        );
    }

    /**
     * @return list<ContextContributor>
     */
    private function contributors(): array
    {
        $contributors = [];

        /** @var list<class-string<ContextContributor>> $classes */
        $classes = (array) config('ai.context_contributors', []);

        foreach ($classes as $class) {
            $contributors[] = $this->container->make($class);
        }

        return $contributors;
    }
}
