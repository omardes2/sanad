<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Delivery lifecycle of an OUTBOUND message, tracked separately from the
 * internal pipeline processing_status. Driven by WhatsApp status webhooks.
 *
 * Happy path advances monotonically: pending → accepted → sent → delivered →
 * read. A status may never move backwards, and `failed` never overrides a
 * message that has already reached `delivered` or `read`.
 */
enum MessageDeliveryStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * Rank along the happy path. Failed is off-path (handled explicitly).
     */
    private const ORDER = [
        'pending' => 0,
        'accepted' => 1,
        'sent' => 2,
        'delivered' => 3,
        'read' => 4,
    ];

    /**
     * May this status legitimately replace the given current status?
     *
     * - identical status → false (idempotent no-op)
     * - failed → only if not already delivered/read/failed
     * - otherwise → only if strictly forward along the happy path
     */
    public function isForwardFrom(self $current): bool
    {
        if ($this === $current) {
            return false;
        }

        if ($this === self::Failed) {
            return ! in_array($current, [self::Delivered, self::Read, self::Failed], true);
        }

        $currentRank = self::ORDER[$current->value] ?? 0;
        $newRank = self::ORDER[$this->value] ?? 0;

        return $newRank > $currentRank;
    }

    /**
     * The timestamp column (if any) that this status stamps.
     */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Sent => 'sent_at',
            self::Delivered => 'delivered_at',
            self::Read => 'read_at',
            default => null,
        };
    }
}
