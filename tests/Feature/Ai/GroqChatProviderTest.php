<?php

declare(strict_types=1);

use App\Data\Ai\AiMessage;
use App\Data\Ai\AiRequest;
use App\Exceptions\Ai\AiConfigurationException;
use App\Exceptions\Ai\AiRateLimitException;
use App\Exceptions\Ai\AiRequestException;
use App\Exceptions\Ai\AiServerException;
use App\Exceptions\Ai\AiTimeoutException;
use App\Providers\Ai\GroqChatProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function groqProvider(array $config = []): GroqChatProvider
{
    return new GroqChatProvider('groq', array_merge([
        'base_url' => 'https://api.groq.com/openai/v1',
        'api_key' => 'test-groq-key',
        'model' => 'llama-3.3-70b-versatile',
    ], $config));
}

function groqRequest(): AiRequest
{
    return new AiRequest(
        messages: [AiMessage::system('أنت سَنَد'), AiMessage::user('مرحبا')],
        temperature: 0.5,
        maxOutputTokens: 600,
        timeout: 20,
    );
}

function groqBody(string $content): array
{
    return [
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 7],
    ];
}

it('sends a correct OpenAI-compatible request and parses the completion', function () {
    Http::fake(['api.groq.com/*' => Http::response(groqBody('أهلًا بك في سَنَد'), 200)]);

    $response = groqProvider()->chat(groqRequest());

    expect($response->text)->toBe('أهلًا بك في سَنَد')
        ->and($response->model)->toBe('llama-3.3-70b-versatile')
        ->and($response->promptTokens)->toBe(12)
        ->and($response->completionTokens)->toBe(7);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/chat/completions')
            && $request->hasHeader('Authorization', 'Bearer test-groq-key')
            && $request['model'] === 'llama-3.3-70b-versatile'
            && $request['temperature'] === 0.5
            && $request['max_tokens'] === 600
            && $request['messages'][0]['role'] === 'system'
            && $request['messages'][1]['content'] === 'مرحبا';
    });
});

it('maps HTTP 429 to a retryable rate-limit exception', function () {
    Http::fake(['api.groq.com/*' => Http::response('', 429)]);

    try {
        groqProvider()->chat(groqRequest());
        $this->fail('expected AiRateLimitException');
    } catch (AiRateLimitException $e) {
        expect($e->retryable())->toBeTrue()
            ->and($e->getMessage())->not->toContain('test-groq-key');
    }
});

it('maps HTTP 5xx to a retryable server exception', function () {
    Http::fake(['api.groq.com/*' => Http::response('', 503)]);

    $call = fn () => groqProvider()->chat(groqRequest());

    expect($call)->toThrow(AiServerException::class);
    expect((new AiServerException('x'))->retryable())->toBeTrue();
});

it('maps HTTP 4xx to a non-retryable request exception', function () {
    Http::fake(['api.groq.com/*' => Http::response('', 401)]);

    try {
        groqProvider()->chat(groqRequest());
        $this->fail('expected AiRequestException');
    } catch (AiRequestException $e) {
        expect($e->retryable())->toBeFalse();
    }
});

it('maps a connection failure/timeout to a retryable timeout exception', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $call = fn () => groqProvider()->chat(groqRequest());

    expect($call)->toThrow(AiTimeoutException::class);
    expect((new AiTimeoutException('x'))->retryable())->toBeTrue();
});

it('treats an empty completion as a non-retryable request error', function () {
    Http::fake(['api.groq.com/*' => Http::response(groqBody('   '), 200)]);

    expect(fn () => groqProvider()->chat(groqRequest()))->toThrow(AiRequestException::class);
});

it('throws a configuration error when the api key or model is missing', function () {
    expect(fn () => groqProvider(['api_key' => ''])->chat(groqRequest()))
        ->toThrow(AiConfigurationException::class);

    expect(fn () => groqProvider(['model' => ''])->chat(groqRequest()))
        ->toThrow(AiConfigurationException::class);
});
