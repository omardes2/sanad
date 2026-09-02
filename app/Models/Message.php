<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'text_content',
        'media_path',
        'metadata',
        'processing_status',
        'processed_at',
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
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
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
