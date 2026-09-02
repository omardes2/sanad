<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookEventStatus;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'external_event_id',
        'payload',
        'status',
        'received_at',
        'processed_at',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookEventStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Events that have been received but not yet processed.
     *
     * @param  Builder<WebhookEvent>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', WebhookEventStatus::Received);
    }
}
