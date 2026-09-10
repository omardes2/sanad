<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Voice notes (V1)
    |--------------------------------------------------------------------------
    |
    | V1 scope is WHATSAPP VOICE NOTES, not generic audio. A WhatsApp voice note
    | arrives as an `audio` message carrying `voice: true` and an Ogg/Opus MIME
    | type; a music file or an audio document attached as a media message is a
    | different product question and is deliberately out of scope.
    |
    | Transcription is an EXTERNAL PAID READ. Every bound below exists so the
    | cheap refusals happen before the expensive call, in this order:
    |
    |   plan → payload shape/MIME → provider byte size → download → duration →
    |   provider request
    |
    */

    // Master switch. Off ⇒ a voice note is accepted, stored and refused with a
    // bounded reason; it is never silently dropped as it was before this phase.
    'enabled' => filter_var(env('VOICE_TRANSCRIPTION_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    | Require WhatsApp's own `voice: true` indicator.
    |
    | This is what keeps V1 to voice notes: an `audio` message WITHOUT the flag
    | is a file the subscriber attached, not something they spoke, and it is
    | refused as `unsupported_audio` rather than quietly widening the phase into
    | podcasts and music. Configurable because the flag is the platform's, and a
    | platform can change what it sends.
    */
    'require_voice_flag' => filter_var(env('VOICE_REQUIRE_VOICE_FLAG', true), FILTER_VALIDATE_BOOL),

    /*
    | The MIME types V1 accepts. WhatsApp voice notes are Ogg/Opus, and the
    | duration reader below understands exactly that container — so the
    | allowlist and the reader are two statements of the same V1 scope. Widening
    | one without the other would produce a duration limit that silently does
    | not apply, which is worse than no limit.
    */
    'allowed_mime_types' => ['audio/ogg', 'audio/opus', 'audio/ogg; codecs=opus'],

    /*
    | Byte ceiling. Enforced TWICE: against `file_size` from the provider's media
    | metadata (so an oversized file is refused before a single byte is
    | downloaded) and again against what actually arrived, because metadata is
    | the provider's claim and the bytes are the fact.
    */
    'max_bytes' => (int) env('VOICE_MAX_BYTES', 8 * 1024 * 1024),

    /*
    | Duration ceiling, in seconds, measured from the downloaded bytes BEFORE
    | the provider request — see App\Support\Voice\OggOpusDuration, which reads
    | the Ogg granule position in pure PHP and needs no ffprobe, ffmpeg or any
    | other OS dependency the deployment does not already guarantee.
    |
    | A byte ceiling is NOT a duration limit and is never presented as one.
    */
    'max_duration_seconds' => (int) env('VOICE_MAX_DURATION_SECONDS', 300),

    /*
    | Maximum PHYSICAL transcription requests per voice note. Two, and never
    | more: an unknown outcome may be retried exactly once.
    |
    | This is NOT exactly-once. A provider can accept, process and charge for
    | audio and still leave us unable to prove it — a crash between its response
    | and our settlement is indistinguishable from a request that never landed.
    | So the guarantee is a BOUNDED at-least-once, the same shape reminder
    | delivery uses, and a persisted transcript is what prevents a replay.
    */
    'max_attempts' => (int) env('VOICE_MAX_ATTEMPTS', 2),

    /*
    | How long a transcription claim is honoured before the sweeper may recover
    | the voice note. Must comfortably exceed one download plus one provider
    | request.
    */
    'lease_seconds' => (int) env('VOICE_LEASE_SECONDS', 300),

    // How many pending/stale voice notes one sweep run handles.
    'batch' => (int) env('VOICE_SWEEP_BATCH', 50),

    /*
    | The queue the transcription job runs on. Separate from `messages` on
    | purpose: a voice note waits on an external download plus an external
    | transcription, and putting that in the same queue as reply delivery would
    | let one slow voice note delay every subscriber's answer. Horizon must
    | list this queue (config/horizon.php) or the jobs pile up unconsumed.
    */
    'queue' => env('VOICE_QUEUE', 'voice'),

    /*
    | The three external waits, in seconds. Their SUM is what matters: it must
    | stay under the Horizon worker timeout (60s), which must itself stay under
    | the redis connection's retry_after (90s). Exceed the worker timeout and
    | the job is killed mid-provider-request — which the claim fencing survives
    | correctly, but only by spending the second of two attempts on audio that
    | may already have been transcribed and charged for.
    |
    |   10 + 20 + 25 = 55 < 60 < 90
    |
    | Raising any of these without re-checking that chain turns a bounded retry
    | into a routine double payment.
    */
    'metadata_timeout' => (int) env('VOICE_METADATA_TIMEOUT', 10),
    'download_timeout' => (int) env('VOICE_DOWNLOAD_TIMEOUT', 20),
    'request_timeout' => (int) env('VOICE_REQUEST_TIMEOUT', 25),

    /*
    | The PRIVATE disk the downloaded audio lives on for the seconds it exists.
    | It must never be a public disk and never be exposed as a URL: the file is
    | deleted in a `finally` on success, failure and exception alike, and
    | `messages.media_path` is never pointed at it — a column pointing at a file
    | we deliberately delete is a lie the next reader has to discover.
    */
    'temp_disk' => env('VOICE_TEMP_DISK', 'local'),
    'temp_directory' => env('VOICE_TEMP_DIRECTORY', 'voice-tmp'),

];
