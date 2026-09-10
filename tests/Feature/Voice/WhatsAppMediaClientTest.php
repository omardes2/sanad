<?php

declare(strict_types=1);

use App\Data\WhatsApp\MediaMetadata;
use App\Exceptions\WhatsApp\MediaFetchException;
use App\Services\WhatsApp\WhatsAppMediaClient;
use Illuminate\Support\Facades\Http;

/**
 * Fetching inbound media: the step that happens BEFORE anything is paid for.
 *
 * The two properties worth proving here are the byte ceiling (enforced twice —
 * against the provider's claim and against the bytes that actually arrive) and
 * the permanent/unknown split, because the caller turns that split directly
 * into "settle this voice note" versus "a retry may still work".
 */
beforeEach(function () {
    config([
        'whatsapp.enabled' => true,
        'whatsapp.access_token' => 'TEST_ACCESS_TOKEN',
        'whatsapp.phone_number_id' => 'PNID_123',
        'whatsapp.graph_base_url' => 'https://graph.facebook.com',
        'whatsapp.graph_version' => 'v21.0',
    ]);
});

function mediaClient(): WhatsAppMediaClient
{
    return app(WhatsAppMediaClient::class);
}

function mediaTempPath(): string
{
    return sys_get_temp_dir().'/voice-media-'.bin2hex(random_bytes(6));
}

it('reads the media metadata and reports the base MIME type without its parameters', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([
        'url' => 'https://lookaside.example/media/1',
        'mime_type' => 'audio/ogg; codecs=opus',
        'file_size' => 1234,
        'id' => 'media-1',
        'sha256' => 'abc',
    ], 200)]);

    $metadata = mediaClient()->metadata('media-1');

    expect($metadata)->toBeInstanceOf(MediaMetadata::class)
        ->and($metadata->sizeBytes)->toBe(1234)
        ->and($metadata->mimeType)->toBe('audio/ogg; codecs=opus')
        // Comparing the bare type is what makes an allowlist mean what it
        // looks like it means.
        ->and($metadata->baseMimeType())->toBe('audio/ogg');
});

it('maps expired media to a PERMANENT failure', function (int $status) {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => 'gone'], $status)]);

    try {
        mediaClient()->metadata('media-1');
        expect(false)->toBeTrue('expected a MediaFetchException');
    } catch (MediaFetchException $e) {
        // WhatsApp media EXPIRES. Retrying forever would be work that can never
        // succeed, so this is the one media failure that must not be retried.
        expect($e->kind)->toBe(MediaFetchException::KIND_GONE)
            ->and($e->retryable())->toBeFalse();
    }
})->with([404, 410]);

it('maps throttling and server errors to UNKNOWN, which a retry may still resolve', function (int $status) {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => 'later'], $status)]);

    try {
        mediaClient()->metadata('media-1');
        expect(false)->toBeTrue('expected a MediaFetchException');
    } catch (MediaFetchException $e) {
        expect($e->kind)->toBe(MediaFetchException::KIND_TRANSIENT)
            ->and($e->retryable())->toBeTrue();
    }
})->with([429, 500, 503]);

it('refuses a file the provider declares as oversized before fetching one byte', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.example/m', 'file_size' => 10_000], 200),
        'lookaside.example/*' => Http::response('should never be requested', 200),
    ]);

    $metadata = mediaClient()->metadata('media-1');
    $path = mediaTempPath();

    try {
        mediaClient()->download($metadata, $path, 1000);
        expect(false)->toBeTrue('expected a MediaFetchException');
    } catch (MediaFetchException $e) {
        expect($e->kind)->toBe(MediaFetchException::KIND_TOO_LARGE);
    } finally {
        @unlink($path);
    }

    // The cheap refusal really was cheap: the binary was never requested.
    expect(collect(Http::recorded())->filter(
        static fn (array $pair): bool => str_contains((string) $pair[0]->url(), 'lookaside.example')
    ))->toBeEmpty();
});

it('aborts mid-stream and leaves no partial file when the bytes exceed the ceiling', function () {
    Http::fake([
        // The provider UNDERSTATES the size. Metadata is a claim; bytes are the
        // fact, and the ceiling has to survive a dishonest claim.
        'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.example/m', 'file_size' => 10], 200),
        'lookaside.example/*' => Http::response(str_repeat('A', 500_000), 200),
    ]);

    $metadata = mediaClient()->metadata('media-1');
    $path = mediaTempPath();

    try {
        mediaClient()->download($metadata, $path, 1000);
        expect(false)->toBeTrue('expected a MediaFetchException');
    } catch (MediaFetchException $e) {
        expect($e->kind)->toBe(MediaFetchException::KIND_TOO_LARGE)
            // A half-downloaded voice note is not audio; leaving it behind
            // would hand the duration reader something to guess about.
            ->and(is_file($path))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('writes the bytes to the given path and returns how many arrived', function () {
    $audio = voiceFixtureBytes();

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'url' => 'https://lookaside.example/m',
            'mime_type' => 'audio/ogg',
            'file_size' => strlen($audio),
        ], 200),
        'lookaside.example/*' => static fn () => Http::response($audio, 200),
    ]);

    $metadata = mediaClient()->metadata('media-1');
    $path = mediaTempPath();

    try {
        $written = mediaClient()->download($metadata, $path, 8 * 1024 * 1024);

        expect($written)->toBe(strlen($audio))
            ->and(file_get_contents($path))->toBe($audio);
    } finally {
        @unlink($path);
    }
});

it('never puts the access token or the signed URL in an exception message', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => 'nope'], 403)]);

    try {
        mediaClient()->metadata('media-1');
        expect(false)->toBeTrue('expected a MediaFetchException');
    } catch (MediaFetchException $e) {
        // The signed URL is itself a credential, and so is the token. Neither
        // may travel in something that gets logged.
        expect($e->getMessage())->not->toContain('TEST_ACCESS_TOKEN')
            ->and($e->getMessage())->not->toContain('lookaside')
            ->and($e->kind)->toBe(MediaFetchException::KIND_REJECTED)
            ->and($e->retryable())->toBeFalse();
    }
});

it('treats a metadata response with no URL as an unusable answer', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'media-1'], 200)]);

    expect(fn () => mediaClient()->metadata('media-1'))->toThrow(MediaFetchException::class);
});
