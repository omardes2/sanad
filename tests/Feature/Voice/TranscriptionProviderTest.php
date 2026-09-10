<?php

declare(strict_types=1);

use App\Contracts\Ai\SupportsChat;
use App\Contracts\Ai\SupportsTranscription;
use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Ai\TranscriptionRequest;
use App\Enums\AiOperation;
use App\Exceptions\Ai\AiConfigurationException;
use App\Exceptions\Ai\AiEmptyResultException;
use App\Exceptions\Ai\AiRateLimitException;
use App\Exceptions\Ai\AiRequestException;
use App\Exceptions\Ai\AiServerException;
use App\Exceptions\Ai\AiTimeoutException;
use App\Providers\Ai\GroqProvider;
use App\Services\Ai\AiManager;
use App\Services\Ai\SanadAiRouter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The transcription CONTRACT and its first adapter.
 *
 * Two things are being defended here. First, that nothing above the adapter
 * knows it is talking to Groq — the request carries no vendor field and the
 * model always arrives from the router, never from a literal. Second, that the
 * adapter maps failures onto retryable-versus-permanent correctly, because the
 * caller decides "unknown" versus "failed" from exactly that distinction, and
 * getting it backwards would either lose a paid transcript or pay twice.
 */
function transcriptionProvider(array $config = []): GroqProvider
{
    return new GroqProvider('groq', array_merge([
        'base_url' => 'https://api.groq.com/openai/v1',
        'api_key' => 'test-key',
        'model' => 'llama-3.3-70b-versatile',
    ], $config));
}

function transcriptionRequest(string $model = 'routed-transcribe-model'): TranscriptionRequest
{
    return new TranscriptionRequest(
        path: base_path('tests/Fixtures/voice/voice-note-7500ms.ogg'),
        mimeType: 'audio/ogg; codecs=opus',
        spec: new ModelSpec('groq', $model, [AiOperation::Transcription]),
        languageHint: 'ar',
        durationMs: 7500,
    );
}

it('declares transcription alongside chat, and implements the contract', function () {
    $provider = transcriptionProvider();

    expect($provider)->toBeInstanceOf(SupportsTranscription::class)
        ->and($provider)->toBeInstanceOf(SupportsChat::class)
        ->and($provider->supports(AiOperation::Transcription))->toBeTrue()
        ->and($provider->supports(AiOperation::Chat))->toBeTrue()
        ->and($provider->supports(AiOperation::Vision))->toBeFalse();
});

it('sends the ROUTED model and never a literal of its own', function () {
    Http::fake(['api.groq.com/*' => Http::response(['text' => 'مرحبا'], 200)]);

    $result = transcriptionProvider()->transcribe(transcriptionRequest('some-catalogued-model'));

    Http::assertSent(function ($request): bool {
        $body = (string) $request->body();

        return str_contains((string) $request->url(), '/audio/transcriptions')
            // Multipart: the model and the language hint travel as fields.
            && str_contains($body, 'some-catalogued-model')
            && str_contains($body, 'name="language"');
    });

    expect($result->text)->toBe('مرحبا')
        ->and($result->provider)->toBe('groq')
        ->and($result->model)->toBe('some-catalogued-model');
});

it('refuses to invent a model when the route carries none', function () {
    Http::fake();

    expect(fn () => transcriptionProvider()->transcribe(transcriptionRequest('')))
        // Substituting a default would spend money on a model no operator chose.
        ->toThrow(AiConfigurationException::class);

    expect(collect(Http::recorded()))->toBeEmpty();
});

it('fails closed with no credential, and with a failed-closed credential', function (array $config) {
    Http::fake();

    expect(fn () => transcriptionProvider($config)->transcribe(transcriptionRequest()))
        ->toThrow(AiConfigurationException::class);

    expect(collect(Http::recorded()))->toBeEmpty();
})->with([
    'no key' => [['api_key' => '']],
    'vault failure' => [['credential_failure' => 'vault_unavailable']],
]);

it('maps provider failures onto retryable and permanent correctly', function (callable $response, string $exception, bool $retryable) {
    Http::fake(['api.groq.com/*' => $response()]);

    try {
        transcriptionProvider()->transcribe(transcriptionRequest());
        expect(false)->toBeTrue('expected '.$exception);
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception)
            ->and($e->retryable())->toBe($retryable)
            // Safe by construction: a status code and a short reason, never a
            // body, a key, or the subscriber's words.
            ->and($e->getMessage())->not->toContain('test-key');
    }
})->with([
    // UNKNOWN: the provider may have processed and charged before failing.
    'rate limited' => [fn () => Http::response(['error' => 'slow down'], 429), AiRateLimitException::class, true],
    'server error' => [fn () => Http::response(['error' => 'boom'], 500), AiServerException::class, true],
    'timeout' => [fn () => fn () => throw new ConnectionException('timed out'), AiTimeoutException::class, true],
    // PERMANENT: nothing was processed, and a retry would only repeat it.
    'rejected' => [fn () => Http::response(['error' => 'bad audio'], 400), AiRequestException::class, false],
    'unauthorized' => [fn () => Http::response(['error' => 'nope'], 401), AiRequestException::class, false],
    // The provider DID the work and produced nothing usable — its own type,
    // because "not retryable" here does not mean "cost nothing".
    'empty transcript' => [fn () => Http::response(['text' => '   '], 200), AiEmptyResultException::class, false],
]);

it('records what the provider reported without letting it become a gate', function () {
    Http::fake(['api.groq.com/*' => Http::response([
        'text' => 'نصّ',
        'language' => 'arabic',
        'duration' => 12.5,
    ], 200)]);

    $result = transcriptionProvider()->transcribe(transcriptionRequest());

    // The provider's duration arrives AFTER we have already paid, so it is
    // recorded and never used to enforce anything.
    expect($result->durationMs)->toBe(12500)
        ->and($result->language)->toBe('arabic')
        ->and($result->isEmpty())->toBeFalse();
});

it('carries no vendor-specific field on the generic request', function () {
    $properties = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(TranscriptionRequest::class))->getProperties(),
    );

    sort($properties);

    // A second provider must be one adapter, not a widened contract. Anything
    // only one vendor understands belongs inside that vendor's adapter.
    expect($properties)->toBe(['durationMs', 'languageHint', 'mimeType', 'path', 'spec', 'timeout']);
});

it('routes transcription to a catalogued transcription model, not to the chat model', function () {
    voiceConfigure();

    $route = app(SanadAiRouter::class)->route(AiOperation::Transcription);

    expect($route->model)->toBe('test-transcribe')
        ->and($route->provider->name())->toBe('groq')
        ->and($route->provider)->toBeInstanceOf(SupportsTranscription::class);

    // And chat still routes to the chat model: one spec claiming both would
    // send audio to a model that cannot take it.
    expect(app(SanadAiRouter::class)->route(AiOperation::Chat)->model)
        ->toBe(config('ai.providers.groq.model'));
});

it('has no transcription route at all when no transcription model is catalogued', function () {
    voiceConfigure(['ai.providers.groq.transcription_model' => null]);
    config(['ai.providers.openai.transcription_model' => null]);

    // Fail closed: the voice path then refuses with a bounded reason instead of
    // guessing a model, which is the whole reason the default is unset.
    expect(fn () => app(SanadAiRouter::class)->route(AiOperation::Transcription))
        ->toThrow(AiConfigurationException::class);
});

it('is resolved by the manager under the provider key the catalog names', function () {
    expect(app(AiManager::class)->provider('groq'))->toBeInstanceOf(GroqProvider::class);
});
