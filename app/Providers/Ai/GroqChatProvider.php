<?php

declare(strict_types=1);

namespace App\Providers\Ai;

use App\Contracts\Ai\AiProvider;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Exceptions\Ai\AiConfigurationException;
use App\Exceptions\Ai\AiRateLimitException;
use App\Exceptions\Ai\AiRequestException;
use App\Exceptions\Ai\AiServerException;
use App\Exceptions\Ai\AiTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Groq provider (OpenAI-compatible Chat Completions API).
 *
 * POST {base_url}/chat/completions with a Bearer key. Transport and HTTP
 * failures are mapped to the typed App\Exceptions\Ai\* hierarchy; the raw body,
 * the API key, and user content are never placed in an exception message or log.
 */
final class GroqChatProvider implements AiProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly string $name,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function chat(AiRequest $request): AiResponse
    {
        $apiKey = $this->requireString('api_key');
        $model = $this->requireString('model');
        $baseUrl = rtrim($this->requireString('base_url'), '/');

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($apiKey)
                ->acceptJson()
                ->timeout($request->timeout)
                ->post('/chat/completions', [
                    'model' => $model,
                    'messages' => $request->messagesArray(),
                    'temperature' => $request->temperature,
                    'max_tokens' => $request->maxOutputTokens,
                ]);
        } catch (ConnectionException) {
            // Timeouts and connection resets — never include the URL/key.
            throw new AiTimeoutException("AI provider [{$this->name}] timed out.");
        }

        $status = $response->status();

        if ($status === 429) {
            throw new AiRateLimitException("AI provider [{$this->name}] rate limited (429).");
        }

        if ($status >= 500) {
            throw new AiServerException("AI provider [{$this->name}] server error ({$status}).");
        }

        if ($response->failed()) {
            // 4xx (bad request / auth) — not retryable.
            throw new AiRequestException("AI provider [{$this->name}] request failed ({$status}).");
        }

        $text = trim((string) $response->json('choices.0.message.content', ''));

        if ($text === '') {
            throw new AiRequestException("AI provider [{$this->name}] returned an empty completion.");
        }

        return new AiResponse(
            text: $text,
            model: $response->json('model'),
            promptTokens: $response->json('usage.prompt_tokens'),
            completionTokens: $response->json('usage.completion_tokens'),
            finishReason: $response->json('choices.0.finish_reason'),
        );
    }

    private function requireString(string $key): string
    {
        $value = $this->config[$key] ?? null;
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            throw AiConfigurationException::missing($this->name, $key);
        }

        return $value;
    }
}
