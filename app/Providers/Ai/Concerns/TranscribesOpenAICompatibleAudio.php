<?php

declare(strict_types=1);

namespace App\Providers\Ai\Concerns;

use App\Data\Ai\TranscriptionRequest;
use App\Data\Ai\TranscriptionResult;
use App\Exceptions\Ai\AiConfigurationException;
use App\Exceptions\Ai\AiEmptyResultException;
use App\Exceptions\Ai\AiRateLimitException;
use App\Exceptions\Ai\AiRequestException;
use App\Exceptions\Ai\AiServerException;
use App\Exceptions\Ai\AiTimeoutException;
use Illuminate\Http\Client\ConnectionException;

/**
 * Speech-to-text over the OpenAI-compatible audio endpoint:
 * `POST {base_url}/audio/transcriptions`, multipart, Bearer key, `{"text": …}`.
 *
 * It lives in a trait rather than in OpenAICompatibleChatProvider because
 * speaking the chat wire format does NOT imply serving audio: an endpoint may
 * do one and not the other, and a provider must only ever declare what it can
 * actually do. Adding transcription to a second compatible provider is then one
 * `use` plus one `supports()` case — never a change to the generic contract.
 *
 * Requires the host class to extend OpenAICompatibleChatProvider, whose
 * `http()`, `apiKey()`, `configString()`, `headers()` and `credentialFailure()`
 * this reuses so credentials, pinned URLs and failed-closed state behave
 * IDENTICALLY here and on the chat path. A second copy of that logic would be a
 * second place for a credential rule to be forgotten.
 *
 * THE MODEL IS NEVER A LITERAL HERE. It arrives on the request as the routed
 * `ModelSpec`, and a missing one is a configuration error rather than a guess:
 * silently substituting a default would spend money on a model no operator
 * chose.
 */
trait TranscribesOpenAICompatibleAudio
{
    public function transcribe(TranscriptionRequest $request): TranscriptionResult
    {
        if ($this->credentialFailure() !== null) {
            throw AiConfigurationException::missing($this->name(), 'api_key ('.$this->credentialFailure().')');
        }

        $apiKey = $this->apiKey();

        if ($apiKey === '') {
            throw AiConfigurationException::missing($this->name(), 'api_key');
        }

        $baseUrl = $this->configString('base_url');

        if ($baseUrl === '') {
            throw AiConfigurationException::missing($this->name(), 'base_url');
        }

        $model = trim($request->spec->model);

        if ($model === '') {
            throw AiConfigurationException::missing($this->name(), 'transcription model');
        }

        $audio = @file_get_contents($request->path);

        if (! is_string($audio) || $audio === '') {
            // The temp file vanished or is empty. Not the provider's fault, and
            // not something a retry against the provider could fix.
            throw new AiRequestException("AI provider [{$this->name()}] was given unreadable audio.");
        }

        $fields = ['model' => $model, 'response_format' => 'json'];

        if ($request->languageHint !== null && $request->languageHint !== '') {
            // A HINT, never a filter: a provider is free to detect otherwise,
            // and a subscriber may speak a language their locale does not name.
            $fields['language'] = $request->languageHint;
        }

        $started = hrtime(true);

        try {
            $response = $this->http($request->timeout)
                ->baseUrl(rtrim($baseUrl, '/'))
                ->withToken($apiKey)
                ->withHeaders($this->headers())
                ->attach('file', $audio, $request->uploadName(), ['Content-Type' => $request->mimeType])
                ->acceptJson()
                ->post('/audio/transcriptions', $fields);
        } catch (ConnectionException) {
            // UNKNOWN, not failed: the provider may have received, processed and
            // charged for this audio. The caller decides what an unknown costs.
            throw new AiTimeoutException("AI provider [{$this->name()}] timed out.");
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $status = $response->status();

        if ($status === 429) {
            throw new AiRateLimitException("AI provider [{$this->name()}] rate limited (429).");
        }

        if ($status >= 500) {
            throw new AiServerException("AI provider [{$this->name()}] server error ({$status}).");
        }

        if ($response->failed()) {
            throw new AiRequestException("AI provider [{$this->name()}] transcription failed ({$status}).");
        }

        $text = trim((string) $response->json('text', ''));

        if ($text === '') {
            // An accepted request that produced nothing. Its own exception type,
            // because the provider DID the work here — retrying would pay twice
            // for the same silence, and treating it as a 4xx would imply nothing
            // was consumed.
            throw new AiEmptyResultException("AI provider [{$this->name()}] returned an empty transcript.");
        }

        $language = $response->json('language');
        $reportedSeconds = $response->json('duration');

        return new TranscriptionResult(
            text: $text,
            provider: $this->name(),
            model: $model,
            language: is_string($language) && $language !== '' ? $language : null,
            // The provider's own reading of the audio, kept separate from the
            // duration Sanad measured before dispatching — this one arrives
            // after we have already paid, so it can never be a gate.
            durationMs: is_numeric($reportedSeconds) ? (int) round(((float) $reportedSeconds) * 1000) : null,
            metadata: ['request_duration_ms' => $durationMs],
        );
    }
}
