<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MemoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Memory extends Model
{
    /** @use HasFactory<MemoryFactory> */
    use HasFactory;

    /**
     * pgvector / embeddings are intentionally deferred (see docs/DATABASE.md);
     * this table stores plain long-term memories for now.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'category',
        'content',
        'importance',
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
            'importance' => 'integer',
            'metadata' => 'array',
            'archived_at' => 'datetime',
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
}
