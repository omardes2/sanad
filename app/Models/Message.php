<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Enums\TranscriptionFailureReason;
use App\Enums\TranscriptionStatus;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'user_id',
        'direction',
        'type',
        'external_message_id',
        'in_reply_to_message_id',
        'reminder_id',
        'text_content',
        'media_path',
        'metadata',
        'processing_status',
        'processed_at',
        'provider_message_id',
        'delivery_status',
        'sent_at',
        'delivered_at',
        'read_at',
        'delivery_error_code',
        'voice_media_id',
        'voice_mime_type',
        'voice_bytes',
        'voice_duration_ms',
        'transcription_status',
        'transcription_provider',
        'transcription_model',
        'transcription_attempts',
        'transcription_claim_token',
        'transcription_claimed_at',
        'transcription_dispatched_at',
        'transcription_failure_reason',
        'transcribed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'type' => MessageType::class,
            'processing_status' => MessageProcessingStatus::class,
            'delivery_status' => MessageDeliveryStatus::class,
            'metadata' => 'array',
            'processed_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'transcription_status' => TranscriptionStatus::class,
            'transcription_failure_reason' => TranscriptionFailureReason::class,
            'voice_bytes' => 'integer',
            'voice_duration_ms' => 'integer',
            'transcription_attempts' => 'integer',
            'transcription_claimed_at' => 'datetime',
            'transcription_dispatched_at' => 'datetime',
            'transcribed_at' => 'datetime',
        ];
    }

    /**
     * Is this a voice note awaiting or undergoing transcription?
     *
     * The audio message IS the subscriber's message — there is never a second
     * synthetic row for the transcript — so these helpers describe how THIS
     * row's `text_content` is being produced.
     */
    public function isVoiceNote(): bool
    {
        return $this->type === MessageType::Audio && $this->voice_media_id !== null;
    }

    /**
     * Fencing: is this voice note still held under exactly THIS claim?
     *
     * The token is the compare-and-set. A worker whose claim was swept and
     * re-issued reads false here and stops — before it can increment attempts
     * and before it can reach the transcription provider. `ShouldBeUnique` is
     * transport protection; this is durable ownership.
     */
    public function isTranscriptionClaimedBy(string $token): bool
    {
        return $this->transcription_claim_token !== null
            && hash_equals($this->transcription_claim_token, $token);
    }

    /**
     * Whether a physical provider request has already been authorised under the
     * CURRENT claim. Every claim clears `transcription_dispatched_at`, so this
     * is a plain fact about this claim: one claim authorises at most one paid
     * request, and a second worker holding the same token stops here.
     */
    public function transcriptionDispatchedUnderCurrentClaim(): bool
    {
        return $this->transcription_dispatched_at !== null;
    }

    /** A transcript exists. It is never produced twice, and never re-paid for. */
    public function hasTranscript(): bool
    {
        return $this->transcription_status === TranscriptionStatus::Transcribed
            && $this->text_content !== null
            && $this->text_content !== '';
    }

    /**
     * Claimed, past its lease, and never settled — the shape a crashed worker
     * leaves behind. The sweeper recovers these; nothing else acts on them.
     */
    public function hasStaleTranscriptionClaim(): bool
    {
        if ($this->transcription_status !== TranscriptionStatus::Processing || $this->transcription_claimed_at === null) {
            return false;
        }

        $lease = max(30, (int) config('voice.lease_seconds', 300));

        return $this->transcription_claimed_at->copy()->addSeconds($lease)->isPast();
    }

    /**
     * Apply an incoming delivery status, honouring monotonic ordering
     * (never moves backwards; `failed` never overrides delivered/read).
     * Returns true if the row was advanced, false if the update was a no-op.
     */
    public function applyDeliveryStatus(MessageDeliveryStatus $status, ?string $errorCode = null): bool
    {
        $current = $this->delivery_status ?? MessageDeliveryStatus::Pending;

        if (! $status->isForwardFrom($current)) {
            return false;
        }

        $attributes = ['delivery_status' => $status];

        if (($column = $status->timestampColumn()) !== null && $this->{$column} === null) {
            $attributes[$column] = now();
        }

        if ($status === MessageDeliveryStatus::Failed && $errorCode !== null) {
            $attributes['delivery_error_code'] = $errorCode;
        }

        $this->forceFill($attributes)->save();

        return true;
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The inbound message this (outbound) message is a reply to.
     *
     * @return BelongsTo<Message, $this>
     */
    public function inReplyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'in_reply_to_message_id');
    }

    /**
     * The single outbound reply to this (inbound) message, if any.
     * Enforced one-per-inbound by a UNIQUE constraint on in_reply_to_message_id.
     *
     * @return HasOne<Message, $this>
     */
    public function reply(): HasOne
    {
        return $this->hasOne(Message::class, 'in_reply_to_message_id');
    }

    /**
     * Only inbound (user → SANAD) messages.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeInbound(Builder $query): void
    {
        $query->where('direction', MessageDirection::Inbound);
    }

    /**
     * Only outbound (SANAD → user) messages.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeOutbound(Builder $query): void
    {
        $query->where('direction', MessageDirection::Outbound);
    }
}
