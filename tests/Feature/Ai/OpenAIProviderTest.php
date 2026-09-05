<?php

declare(strict_types=1);

use App\Data\Ai\AiMessage;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiToolCall;
use App\Data\Ai\AiToolDefinition;
use App\Exceptions\Ai\AiRequestException;
use App\Providers\Ai\OpenAIProvider;
use Illuminate\Support\Facades\Http;

function openaiProvider(array $config = []): OpenAIProvider
{
    return new OpenAIProvider('openai', array_merge([
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'test-openai-key',
        'model' => 'gpt-4.1-mini',
    ], $config));
}

function openaiRequest(): AiRequest
{
    return new AiRequest(
        messages: [AiMessage::system('أنت سَنَد'), AiMessage::user('مرحبا')],
        temperature: 0.5,
        maxOutputTokens: 600,
        timeout: 20,
    );
}

function openaiBody(string $content, array $extra = []): array
{
    return array_merge([
        'model' => 'gpt-4.1-mini',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 7, 'prompt_tokens_details' => ['cached_tokens' => 5]],
    ], $extra);
}

it('sends an OpenAI request with max_completion_tokens and parses usage incl. cached tokens', function () {
    Http::fake(['api.openai.com/*' => Http::response(openaiBody('أهلًا بك في سَنَد'), 200)]);

    $response = openaiProvider()->chat(openaiRequest());

    expect($response->text)->toBe('أهلًا بك في سَنَد')
        ->and($response->provider)->toBe('openai')
        ->and($response->model)->toBe('gpt-4.1-mini')
        ->and($response->promptTokens)->toBe(12)
        ->and($response->completionTokens)->toBe(7)
        ->and($response->cachedTokens)->toBe(5)
        ->and($response->durationMs)->toBeInt()
        ->and($response->hasToolCalls())->toBeFalse();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.openai.com/v1/chat/completions')
            && $request->hasHeader('Authorization', 'Bearer test-openai-key')
            && $request['model'] === 'gpt-4.1-mini'
            && $request['max_completion_tokens'] === 600
            && ! isset($request['max_tokens'])
            && ! isset($request['tools']);
    });
});

it('uses the routed model instead of the configured default', function () {
    Http::fake(['api.openai.com/*' => Http::response(openaiBody('رد'), 200)]);

    openaiProvider()->chat(openaiRequest()->withModel('gpt-4.1'));

    Http::assertSent(fn ($request) => $request['model'] === 'gpt-4.1');
});

it('sends optional organization/project scoping headers only when configured', function () {
    Http::fake(['api.openai.com/*' => Http::response(openaiBody('رد'), 200)]);

    openaiProvider(['organization' => 'org_123', 'project' => 'proj_456'])->chat(openaiRequest());

    Http::assertSent(fn ($request) => $request->hasHeader('OpenAI-Organization', 'org_123')
        && $request->hasHeader('OpenAI-Project', 'proj_456'));

    Http::fake(['api.openai.com/*' => Http::response(openaiBody('رد'), 200)]);
    openaiProvider()->chat(openaiRequest());

    Http::assertSent(fn ($request) => ! $request->hasHeader('OpenAI-Organization'));
});

it('translates provider-agnostic tool definitions to the wire format and parses tool calls back', function () {
    Http::fake(['api.openai.com/*' => Http::response(openaiBody('', [
        'choices' => [['message' => [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'create_reminder', 'arguments' => '{"title":"اتصل بالمحاسب","when":"2026-09-06T09:00:00+03:00"}'],
            ], [
                'id' => 'call_2',
                'type' => 'function',
                'function' => ['name' => 'log_expense', 'arguments' => 'not-json'],
            ]],
        ], 'finish_reason' => 'tool_calls']],
    ]), 200)]);

    $tool = new AiToolDefinition(
        name: 'create_reminder',
        description: 'ينشئ تذكيرًا',
        parameters: ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']],
    );

    $response = openaiProvider()->chat(openaiRequest()->withTools([$tool]));

    // Empty text is fine when the model answered with tool calls only.
    expect($response->text)->toBe('')
        ->and($response->hasToolCalls())->toBeTrue()
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolCalls[0])->toBeInstanceOf(AiToolCall::class)
        ->and($response->toolCalls[0]->id)->toBe('call_1')
        ->and($response->toolCalls[0]->name)->toBe('create_reminder')
        ->and($response->toolCalls[0]->arguments['title'])->toBe('اتصل بالمحاسب')
        // Malformed JSON arguments → empty set; the platform validates, not the provider.
        ->and($response->toolCalls[1]->arguments)->toBe([]);

    Http::assertSent(function ($request) {
        $tools = $request['tools'];

        return $request['tool_choice'] === 'auto'
            && $tools[0]['type'] === 'function'
            && $tools[0]['function']['name'] === 'create_reminder'
            && $tools[0]['function']['parameters']['required'] === ['title'];
    });
});

it('still rejects a completion with neither text nor tool calls', function () {
    Http::fake(['api.openai.com/*' => Http::response(openaiBody('   '), 200)]);

    expect(fn () => openaiProvider()->chat(openaiRequest()))->toThrow(AiRequestException::class);
});

it('reports configured only when a key and endpoint are present', function () {
    expect(openaiProvider()->isConfigured())->toBeTrue()
        ->and(openaiProvider(['api_key' => ''])->isConfigured())->toBeFalse()
        ->and(openaiProvider(['base_url' => ''])->isConfigured())->toBeFalse();
});
