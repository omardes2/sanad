<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Enums\TranscriptionFailureReason;
use App\Models\Message;

/**
 * Composes the ONE reply a subscriber gets when their voice note did not become
 * text, and stages it as the message's single outbound reply.
 *
 * ── WHY A ROW, NOT A SEND ────────────────────────────────────────────────────
 * This does not talk to WhatsApp. It creates the reply row that the ordinary
 * reply path already knows how to deliver, so a refusal goes out through the
 * SAME delivery, retry and status-tracking code as every other answer. The
 * alternative — a direct send from the voice pipeline — would be a second
 * delivery path that nobody maintains, with its own retry semantics and its own
 * ways of sending twice.
 *
 * ── ONE REPLY, ALWAYS ────────────────────────────────────────────────────────
 * `in_reply_to_message_id` is UNIQUE, so a voice note has exactly one reply
 * whatever happens to it: a refusal cannot arrive alongside a transcript's
 * answer, and two workers cannot both apologise. `createOrFirst` makes that
 * race resolve in the database rather than in a check-then-insert.
 *
 * ── WHAT THE SUBSCRIBER READS ────────────────────────────────────────────────
 * A translated sentence keyed by a closed reason code, in THEIR locale — never
 * a provider message, an HTTP status, a model name, or a stack trace. The
 * pipeline decides what happened; lang/<locale>/voice.php decides how it is
 * said. That separation is what lets Sanad answer a Saudi subscriber in Arabic
 * about a provider timeout without either fact leaking into the other — and it
 * is why the sentence does not change when the routed provider does.
 */
class VoiceRefusalReply
{
    /**
     * Stage the reply for this voice note. Returns the reply message.
     *
     * Idempotent: called twice, it returns the row it already created.
     */
    public function stage(Message $voiceNote, TranscriptionFailureReason $reason): Message
    {
        return Message::query()->createOrFirst(
            ['in_reply_to_message_id' => $voiceNote->getKey()],
            [
                'conversation_id' => $voiceNote->conversation_id,
                'user_id' => $voiceNote->user_id,
                'direction' => MessageDirection::Outbound,
                'type' => MessageType::Text,
                'external_message_id' => null,
                'text_content' => $this->text($voiceNote, $reason),
                'metadata' => [
                    // The CODE, for operators. The sentence above is for the
                    // subscriber; neither is derived from the other at read time.
                    'voice_failure_reason' => $reason->value,
                ],
                'processing_status' => MessageProcessingStatus::Queued,
            ],
        );
    }

    /**
     * The sentence, in the subscriber's language.
     *
     * A locale with no `voice.php` falls through to the fallback locale rather
     * than rendering the key: a subscriber must never receive
     * "voice.failure.media_expired".
     */
    private function text(Message $voiceNote, TranscriptionFailureReason $reason): string
    {
        $locale = trim((string) ($voiceNote->user?->locale ?? '')) ?: null;
        $key = $reason->translationKey();
        $line = trans($key, [], $locale);

        if (! is_string($line) || $line === $key) {
            $line = trans($key, [], (string) config('app.fallback_locale', 'en'));
        }

        // Last resort: a translation file that is missing the code entirely.
        // Still a sentence, still never the key.
        return is_string($line) && $line !== $key
            ? $line
            : trans(TranscriptionFailureReason::Internal->translationKey(), [], (string) config('app.fallback_locale', 'en'));
    }
}
