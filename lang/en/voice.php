<?php

declare(strict_types=1);

/**
 * English counterpart of lang/ar/voice.php — see that file for the rules every
 * line here follows. This is also the FALLBACK locale, so a code added to
 * App\Enums\TranscriptionFailureReason must be given a line here even before
 * anyone translates it, or the subscriber would receive a raw key.
 */
return [

    'failure' => [

        'voice_not_in_plan' => 'Voice messages are not included in your current plan. Send your message as text, or upgrade to enable voice.',

        'unsupported_audio' => "I couldn't read that audio. Try recording a WhatsApp voice note, or send your message as text.",

        'audio_too_large' => 'That audio file is larger than I can handle. Try a shorter recording, or send your message as text.',

        'audio_too_long' => 'That voice message is longer than I can transcribe. Split it into shorter ones, or send your message as text.',

        'media_download_failed' => "I couldn't download your voice message. Please send it again, or write to me instead.",

        'media_expired' => 'That voice message is no longer available from WhatsApp. Please send it again, or write to me instead.',

        'transcription_not_configured' => 'Voice transcription is not available right now. Please send your message as text.',

        'transcription_failed' => "I couldn't transcribe your voice message. Please try again, or write to me instead.",

        'transcription_unknown' => "I didn't get the text of your voice message. Please send it again, or write to me instead.",

        'transcript_empty' => "I couldn't make out any speech in that voice message. Try recording somewhere quieter, or write to me instead.",

        'internal' => 'Something went wrong while processing your voice message. Please try again, or write to me instead.',

    ],

];
