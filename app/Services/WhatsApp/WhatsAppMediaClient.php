<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Data\WhatsApp\MediaMetadata;
use App\Exceptions\WhatsApp\MediaFetchException;
use App\Support\WhatsApp\WhatsAppConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches ONE inbound media object from the WhatsApp Cloud API, in the two
 * steps the platform requires:
 *
 *   1. GET {graph}/{version}/{media_id}   → metadata, incl. a signed URL
 *   2. GET {signed url}                    → the bytes
 *
 * The split is not an inconvenience, it is the cheap refusal: the metadata
 * carries `file_size`, so a file over the ceiling is refused having downloaded
 * nothing. The declared size is still only the provider's CLAIM, so the bytes
 * are counted as they arrive and the download is ABORTED mid-stream the moment
 * it exceeds the ceiling. Trusting the header alone would make the limit
 * advisory; downloading first and measuring after would make it pointless.
 *
 * The bytes go straight to a caller-supplied path and are never held whole in
 * memory, never logged, and never returned. The caller owns deleting that file.
 *
 * NOTHING here reaches the transcription provider. Every failure at this stage
 * is therefore, unambiguously, a failure with no external cost attached — which
 * is exactly why the download is a separate, earlier step.
 */
class WhatsAppMediaClient
{
    /** Streamed in modest chunks so the ceiling is enforced continuously. */
    private const CHUNK_BYTES = 65536;

    public function __construct(private readonly WhatsAppConfig $config) {}

    /**
     * Step 1: what the provider says about this media object.
     *
     * @throws MediaFetchException
     */
    public function metadata(string $mediaId): MediaMetadata
    {
        $this->config->assertCanSend();

        $url = sprintf('%s/%s/%s', $this->config->graphBaseUrl, $this->config->graphVersion, $mediaId);

        try {
            $response = Http::withToken($this->config->accessToken())
                ->timeout($this->timeout('metadata_timeout', 10))
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException) {
            throw MediaFetchException::transient();
        }

        $this->assertUsable($response->status());

        $signed = $response->json('url');

        if (! is_string($signed) || $signed === '') {
            throw MediaFetchException::malformed();
        }

        $size = $response->json('file_size');
        $mime = $response->json('mime_type');
        $sha = $response->json('sha256');

        return new MediaMetadata(
            id: $mediaId,
            url: $signed,
            mimeType: is_string($mime) && $mime !== '' ? $mime : null,
            sizeBytes: is_numeric($size) ? (int) $size : null,
            sha256: is_string($sha) && $sha !== '' ? $sha : null,
        );
    }

    /**
     * Step 2: stream the bytes to $path, refusing anything over $maxBytes.
     *
     * Returns how many bytes were written. On ANY failure the partial file is
     * removed here — a half-downloaded voice note is not audio, and leaving it
     * behind would hand the duration reader something it would have to guess
     * about.
     *
     * @return int the number of bytes written
     *
     * @throws MediaFetchException
     */
    public function download(MediaMetadata $media, string $path, int $maxBytes): int
    {
        // The provider's own claim, refused before a single byte is fetched.
        if ($media->sizeBytes !== null && $media->sizeBytes > $maxBytes) {
            throw MediaFetchException::tooLarge($media->sizeBytes, $maxBytes);
        }

        try {
            $response = Http::withToken($this->config->accessToken())
                ->timeout($this->timeout('download_timeout', 20))
                ->withOptions(['stream' => true])
                ->get($media->url);
        } catch (ConnectionException) {
            throw MediaFetchException::transient();
        }

        $this->assertUsable($response->status());

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw MediaFetchException::transient();
        }

        $written = 0;

        try {
            $body = $response->toPsrResponse()->getBody();

            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);

                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);

                if ($written > $maxBytes) {
                    // Abort mid-stream: the rest of the file is never fetched.
                    throw MediaFetchException::tooLarge($written, $maxBytes);
                }

                if (fwrite($handle, $chunk) === false) {
                    throw MediaFetchException::transient();
                }
            }
        } catch (MediaFetchException $e) {
            fclose($handle);
            @unlink($path);

            throw $e;
        } catch (Throwable) {
            fclose($handle);
            @unlink($path);

            // A transport failure part-way through a stream. Unknown, not
            // permanent — but with no provider request behind it either.
            throw MediaFetchException::transient();
        }

        fclose($handle);

        if ($written === 0) {
            @unlink($path);

            throw MediaFetchException::malformed();
        }

        return $written;
    }

    /**
     * Map an HTTP status onto the permanent/unknown distinction the caller
     * needs. 404/410 is the one that matters most: WhatsApp media EXPIRES, and
     * retrying an expired voice note forever would be work that can never
     * succeed.
     *
     * @throws MediaFetchException
     */
    private function assertUsable(int $status): void
    {
        if ($status === 404 || $status === 410) {
            throw MediaFetchException::gone($status);
        }

        if ($status === 429 || $status >= 500) {
            throw MediaFetchException::transient($status);
        }

        if ($status >= 400) {
            throw MediaFetchException::rejected($status);
        }
    }

    private function timeout(string $key, int $default): int
    {
        return max(1, (int) config('voice.'.$key, $default));
    }
}
