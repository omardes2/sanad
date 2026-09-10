<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemoryCategory;
use App\Enums\MemoryProvenance;
use Database\Factories\MemoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One durable personal memory (Phase G).
 *
 * `content` holds CIPHERTEXT, never plaintext: an AES-256-GCM envelope sealed
 * by `MemoryCipher` under a key independent of APP_KEY. Nothing in this model
 * decrypts — reading a memory goes through `MemoryService`, which opens only
 * one subscriber's bounded active set in application memory. There is no
 * accessor, no cast and no scope here that would let content be decrypted by
 * accident, in a listing, or across subscribers.
 *
 * `fingerprint` is a keyed MAC of the normalised plaintext and is UNIQUE per
 * (user_id, category) among ACTIVE rows — archiving releases it to NULL.
 *
 * pgvector / embeddings remain deferred (ADR-0008): retrieval in V1 is a
 * bounded, deterministic ordering, not a similarity search.
 *
 * @property string $content the sealed envelope, not readable text
 */
class Memory extends Model
{
    /** @use HasFactory<MemoryFactory> */
    use HasFactory;

    /**
     * `user_id` is fillable because the factories and the one domain writer
     * both set it from trusted context; no tool schema declares it, so no
     * payload can reach it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'category',
        'content',
        'fingerprint',
        'importance',
        'provenance',
        'source_message_id',
        'metadata',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => MemoryCategory::class,
            'importance' => 'integer',
            'provenance' => MemoryProvenance::class,
            'metadata' => 'array',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /**
     * Only active (non-archived) memories.
     *
     * @param  Builder<Memory>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * One subscriber's active memories, most important first — the single
     * ordering used by retrieval AND by the prompt contributor, so what the
     * model can look up and what it is told without asking never disagree.
     *
     * @param  Builder<Memory>  $query
     */
    public function scopeForSubscriber(Builder $query, int $subscriberId): void
    {
        $query->where('user_id', $subscriberId)
            ->whereNull('archived_at')
            ->orderByDesc('importance')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }
}
