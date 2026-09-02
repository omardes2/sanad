<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use Database\Factories\ChannelAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelAccount extends Model
{
    /** @use HasFactory<ChannelAccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'channel',
        'external_identifier',
        'display_name',
        'metadata',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
            'status' => ChannelAccountStatus::class,
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
