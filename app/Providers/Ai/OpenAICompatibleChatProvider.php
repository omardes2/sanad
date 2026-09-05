<?php

declare(strict_types=1);

namespace App\Providers\Ai;

use App\Contracts\Ai\ReportsCredentialState;
use App\Contracts\Ai\SupportsHealthChecks;
use App\Contracts\Ai\SupportsTools;
use App\Data\Ai\AiMessage;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Data\Ai\AiToolCall;
use App\Data\Ai\AiToolDefinition;
use App\Data\Ai\Health\HealthCapabilities;
use App\Data\Ai\Health\HealthProbeContext;
use App\Data\Ai\Health\HealthProbeResult;
use App\Enums\AiOperation;
use App\Enums\HealthCheckKind;
use App\Enums\HealthCheckStatus;
use App\Exceptions\Ai\AiConfigurationException;
use App\Exceptions\Ai\AiException;
use App\Exceptions\Ai\AiRateLimitException;
use App\Exceptions\Ai\AiRequestException;
use App\Exceptions\Ai\AiServerException;
use App\Exceptions\Ai\AiTimeoutException;
use App\Support\Security\SecretString;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

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
 *
 * Phase C3: `api_key` may be a SecretString (from the vault or the
 * environment, chosen by CredentialResolver) or a plain string (tests /
 * extend() factories); `credential_failure` marks a provider that is FAILED
 * CLOSED. Health probes: connectivity (no credential), auth (only when the
 * concrete adapter declares a non-billable probe) and inference (billable).
 * `http_options` (Test Connection candidate URLs only) pins the connection.
 */
abstract class OpenAICompatibleChatProvider implements ReportsCredentialState, SupportsHealthChecks, SupportsTools
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
        return $this->credentialFailure() === null && $this->apiKey() !== '' && $this->configString('base_url') !== '';
    }

    public function credentialFailure(): ?string
    {
        $failure = $this->config['credential_failure'] ?? null;

        return is_string($failure) && $failure !== '' ? $failure : null;
    }

    public function credentialSource(): string
    {
        $source = $this->config['credential_source'] ?? null;

        return is_string($source) && $source !== '' ? $source : ($this->apiKey() !== '' ? 'env' : 'none');
    }

    public function healthCapabilities(): HealthCapabilities
    {
        // An unknown OpenAI-compatible endpoint: nothing is assumed free.
        return new HealthCapabilities(nonBillableAuthProbe: false);
    }

    public function healthCheck(HealthCheckKind $kind, HealthProbeContext $context): HealthProbeResult
    {
        return match ($kind) {
            HealthCheckKind::Connectivity => $this->probeModels($context, withCredential: false),
            HealthCheckKind::Auth => $this->healthCapabilities()->nonBillableAuthProbe
                ? $this->probeModels($context, withCredential: true)
                : new HealthProbeResult(HealthCheckStatus::Skipped, errorCode: 'unsupported'),
            HealthCheckKind::Inference => $this->probeInference($context),
        };
    }

    /**
     * GET {base_url}/models. Without a credential this only proves the
     * endpoint answers over TLS (any HTTP status counts, 401 included); with
     * a credential it proves the key is accepted and lists the models.
     */
    private function probeModels(HealthProbeContext $context, bool $withCredential): HealthProbeResult
    {
        $baseUrl = $this->configString('base_url');

        if ($baseUrl === '') {
            return new HealthProbeResult(HealthCheckStatus::Failed, errorCode: 'missing_base_url');
        }

        $path = $this->healthCapabilities()->authProbePath ?? '/models';
        $request = $this->http($context->timeout)->connectTimeout($context->connectTimeout)->baseUrl(rtrim($baseUrl, '/'));

        if ($withCredential) {
            if ($this->apiKey() === '') {
                return new HealthProbeResult(HealthCheckStatus::Failed, errorCode: 'missing_credential');
            }

            $request = $request->withToken($this->apiKey())->withHeaders($this->headers());
        }

        $started = hrtime(true);

        try {
            $response = $request->acceptJson()->get($path);
        } catch (ConnectionException $e) {
            return new HealthProbeResult(HealthCheckStatus::Failed, self::elapsed($started), null, $e::class, 'connection');
        } catch (Throwable $e) {
            return new HealthProbeResult(HealthCheckStatus::Failed, self::elapsed($started), null, $e::class, 'transport');
        }

        $status = $response->status();
        $latency = self::elapsed($started);

        if (! $withCredential) {
            // Reachability only: any answer from the endpoint is a success.
            return new HealthProbeResult(HealthCheckStatus::Ok, $latency, $status);
        }

        if ($status === 429) {
            return new HealthProbeResult(HealthCheckStatus::Degraded, $latency, $status, null, 'rate_limited');
        }

        if ($status >= 500) {
            return new HealthProbeResult(HealthCheckStatus::Degraded, $latency, $status, null, 'server_error');
        }

        if ($status === 401 || $status === 403) {
            return new HealthProbeResult(HealthCheckStatus::Failed, $latency, $status, null, 'unauthorized');
        }

        if ($response->failed()) {
            return new HealthProbeResult(HealthCheckStatus::Failed, $latency, $status, null, 'http_error');
        }

        $ids = [];

        foreach ((array) $response->json('data', []) as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $ids[] = $row['id'];
            }
        }

        $known = array_values(array_intersect($context->expectedModels, $ids));
        $unknown = array_values(array_diff($context->expectedModels, $ids));

        return new HealthProbeResult(HealthCheckStatus::Ok, $latency, $status, details: [
            'models_listed' => count($ids),
            'catalog_models_known' => $known,
            'catalog_models_unknown' => $unknown,
        ]);
    }

    /**
     * One minimal completion — BILLABLE. Only ProviderHealthService may call
     * this (manual + typed confirmation) and it records the usage.
     */
    private function probeInference(HealthProbeContext $context): HealthProbeResult
    {
        $started = hrtime(true);

        try {
            $response = $this->chat(new AiRequest(
                messages: [AiMessage::user('ping')],
                temperature: 0.0,
                maxOutputTokens: 1,
                timeout: $context->timeout,
                model: $context->model,
            ));
        } catch (AiException $e) {
            return new HealthProbeResult(HealthCheckStatus::Failed, self::elapsed($started), null, $e::class, $e->retryable() ? 'transient' : 'request_failed');
        } catch (Throwable $e) {
            return new HealthProbeResult(HealthCheckStatus::Failed, self::elapsed($started), null, $e::class, 'transport');
        }

        return new HealthProbeResult(
            HealthCheckStatus::Ok,
            $response->durationMs ?? self::elapsed($started),
            200,
            inputTokens: $response->promptTokens,
            outputTokens: $response->completionTokens,
            reportedModel: $response->model,
        );
    }

    private static function elapsed(int $startedNs): int
    {
        return (int) ((hrtime(true) - $startedNs) / 1_000_000);
    }

    /**
     * The base client; `http_options` (a pinned Test Connection candidate)
     * are applied when present.
     */
    protected function http(int $timeout): PendingRequest
    {
        $request = Http::timeout($timeout);
        $options = $this->config['http_options'] ?? null;

        return is_array($options) && $options !== [] ? $request->withOptions($options) : $request;
    }

    /**
     * The credential as a string, from a SecretString or a plain string.
     * Revealed only here, at the moment the request is built.
     */
    protected function apiKey(): string
    {
        $value = $this->config['api_key'] ?? null;

        if ($value instanceof SecretString) {
            return $value->reveal();
        }

        return is_string($value) ? trim($value) : '';
    }

    public function chat(AiRequest $request): AiResponse
    {
        if ($this->credentialFailure() !== null) {
            throw AiConfigurationException::missing($this->name, 'api_key ('.$this->credentialFailure().')');
        }

        $apiKey = $this->apiKey();

        if ($apiKey === '') {
            throw AiConfigurationException::missing($this->name, 'api_key');
        }

        $baseUrl = rtrim($this->requireString('base_url'), '/');
        // The router stamps the model it chose; fall back to the configured default.
        $model = trim((string) $request->model);
        $model = $model !== '' ? $model : $this->requireString('model');

        $started = hrtime(true);

        try {
            $response = $this->http($request->timeout)
                ->baseUrl($baseUrl)
                ->withToken($apiKey)
                ->withHeaders($this->headers())
                ->acceptJson()
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
