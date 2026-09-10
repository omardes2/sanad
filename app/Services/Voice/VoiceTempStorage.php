<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\Message;
use App\Support\SafeError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Where a voice note's bytes live for the seconds they exist.
 *
 * The audio is NOT stored. It is downloaded, measured, uploaded once and
 * deleted — on success, on refusal and on exception alike. This class exists so
 * that "deleted" is one implementation with one place to get right, rather than
 * a `unlink()` repeated in every branch of the pipeline, one of which would
 * eventually be forgotten.
 *
 * The disk is PRIVATE by configuration and must stay so: the file is never
 * exposed as a URL, never written to `messages.media_path`, and never given a
 * name derived from anything the subscriber said. The name is the message id
 * plus random bytes — the id so an operator can correlate a stray file with a
 * message, the randomness so two attempts at the same message can never write
 * to the same path and delete each other's audio mid-upload.
 */
class VoiceTempStorage
{
    /** A fresh, unguessable path for one attempt at one message. */
    public function path(Message $message): string
    {
        $disk = Storage::disk($this->disk());
        $directory = trim((string) config('voice.temp_directory', 'voice-tmp'), '/');

        // makeDirectory is idempotent; the disk's own root is created with it.
        $disk->makeDirectory($directory);

        return $disk->path(sprintf(
            '%s/%d-%s.ogg',
            $directory,
            $message->getKey(),
            bin2hex(random_bytes(8)),
        ));
    }

    /**
     * Delete the file, whatever state it is in.
     *
     * Never throws: this runs in a `finally`, and an exception raised while
     * cleaning up would replace the real outcome of the pipeline with a
     * housekeeping error. A file that cannot be deleted is logged instead —
     * that is an operator problem, not a subscriber's.
     */
    public function forget(string $path): void
    {
        try {
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (Throwable $e) {
            Log::warning('sanad.voice.temp_not_deleted', ['error' => SafeError::summarize($e)]);
        }
    }

    private function disk(): string
    {
        return (string) config('voice.temp_disk', 'local');
    }
}
