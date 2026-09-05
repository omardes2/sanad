<?php

declare(strict_types=1);

namespace App\Providers\Ai;

use App\Contracts\Ai\SupportsTools;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Data\Ai\AiToolCall;
use App\Data\Ai\AiToolDefinition;
use App\Enums\AiOperation;
use App\Exceptions\Ai\AiConfigurationException;
use App\Exceptions\Ai\AiRateLimitException;
use App\Exceptions\Ai\AiRequestException;
use App\Exceptions\Ai\AiServerException;
use App\Exceptions\Ai\AiTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Shared implementation for providers speaking the OpenAI Chat Completions
 * wire format (OpenAI, Groq, and most compatible endpoints):
 * POST {base_url}/chat/completions with a Bearer key.
 *
 * Responsibilities: build the payload from the internal AiRequest (including
 * translating AiToolDefinition → "function" tools), map transport/HTTP failures
 * to the typed App\Exceptions\Ai\* hierarchy, and parse the reply into the
 * internal AiResponse (text, usage incl. cached tokens, duration, and
 * tool_calls → AiToolCall). Subclasses only override the few knobs that differ
 * between vendors. The raw body, the API key, and user content are never placed
 * in an exception message or log.
 */
abstract class OpenAICompatibleChatProvider implements SupportsTools
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly string $name,
        protected readonly array $config,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function supports(AiOperation $operation): bool
    {
        return $operation === AiOperation::Chat;
    }

    public function isConfigured(): bool
    {
        return $this->configString('api_key') !== '' && $this->configString('base_url') !== '';
    }

    public function chat(AiRequest $request): AiResponse
    {
        $apiKey = $this->requireString('api_key');
        $baseUrl = rtrim($this->requireString('base_url'), '/');
        // The router stamps the model it chose; fall back to the configured default.
        $model = trim((string) $request->model);
        $model = $model !== '' ? $model : $this->requireString('model');

        $started = hrtime(true);

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($apiKey)
                ->withHeaders($this->headers())
                ->acceptJson()
                ->timeout($request->timeout)
                ->post('/chat/completions', $this->payload($request, $model));
        } catch (ConnectionException) {
            // Timeouts and connection resets — never include the URL/key.
            throw new AiTimeoutException("AI provider [{$this->name}] timed out.");
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
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
        $toolCalls = $this->parseToolCalls($response->json('choices.0.message.tool_calls'));

        if ($text === '' && $toolCalls === []) {
            throw new AiRequestException("AI provider [{$this->name}] returned an empty completion.");
        }

        return new AiResponse(
            text: $text,
            model: $this->stringOrNull($response->json('model')),
            promptTokens: $this->intOrNull($response->json('usage.prompt_tokens')),
            completionTokens: $this->intOrNull($response->json('usage.completion_tokens')),
            finishReason: $this->stringOrNull($response->json('choices.0.finish_reason')),
            cachedTokens: $this->intOrNull($response->json('usage.prompt_tokens_details.cached_tokens')),
            durationMs: $durationMs,
            toolCalls: $toolCalls,
            provider: $this->name,
        );
    }

    /**
     * Wire key for the output-token cap. OpenAI moved to max_completion_tokens;
     * Groq and most compatible endpoints still use max_tokens.
     */
    protected function maxTokensKey(): string
    {
        return 'max_tokens';
    }

    /**
     * Extra vendor headers (e.g. OpenAI organization/project). None by default.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AiRequest $request, string $model): array
    {
        $payload = [
            'model' => $model,
            'messages' => $request->messagesArray(),
            'temperature' => $request->temperature,
            $this->maxTokensKey() => $request->maxOutputTokens,
        ];

        if ($request->hasTools()) {
            $payload['tools'] = array_map(
                static fn (AiToolDefinition $tool): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->name,
                        'description' => $tool->description,
                        'parameters' => $tool->parameters,
                    ],
                ],
                $request->tools,
            );
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /**
     * Translate OpenAI-style tool_calls into internal AiToolCall values. Arguments
     * arrive as a JSON string; malformed JSON yields an empty argument set so the
     * platform's validation (not the provider) decides what to do with it.
     *
     * @return list<AiToolCall>
     */
    private function parseToolCalls(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $calls = [];

        foreach ($raw as $call) {
            if (! is_array($call)) {
                continue;
            }

            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $name = trim((string) ($function['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $arguments = $function['arguments'] ?? [];

            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            } elseif (! is_array($arguments)) {
                $arguments = [];
            }

            $calls[] = new AiToolCall((string) ($call['id'] ?? ''), $name, $arguments);
        }

        return $calls;
    }

    protected function requireString(string $key): string
    {
        $value = $this->configString($key);

        if ($value === '') {
            throw AiConfigurationException::missing($this->name, $key);
        }

        return $value;
    }

    protected function configString(string $key): string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
