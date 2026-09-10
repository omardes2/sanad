<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Enums\ChannelType;
use App\Enums\ReminderFailureReason;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Admin filters over `reminders`, including everything the delivery phase added
 * and the dashboard never surfaced: the failure reason, the claim and dispatch
 * stamps, and the physical attempt count.
 *
 * `last_error` holds a `ReminderFailureReason` VALUE — `ReminderDispatcher` is
 * the only writer and only ever writes enum values — but the column itself is
 * free-form `text` from Sprint 0. So the filter is validated against the enum
 * before it reaches SQL (an unknown reason matches nothing rather than becoming
 * an arbitrary LIKE), and the model exposes a `tryFrom` accessor rather than an
 * enum cast, which would throw on any legacy row that ever held prose.
 */
final class ReminderQuery
{
    public const MAX_DAYS = 180;

    public const DEFAULT_DAYS = 30;

    /** @var list<string> */
    public const FILTERS = ['status', 'reason', 'channel', 'subscriber_id', 'attempts', 'claimed'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Reminder>
     *
     * @throws InvalidArgumentException
     */
    public static function build(CarbonImmutable $from, CarbonImmutable $to, array $filters = [], int $maxDays = self::MAX_DAYS): Builder
    {
        self::assertWindow($from, $to, $maxDays);

        $value = static fn (string $key): string => trim((string) ($filters[$key] ?? ''));

        $status = $value('status');
        $reason = $value('reason');
        $channel = $value('channel');
        $subscriber = $value('subscriber_id');
        $attempts = $value('attempts');
        $claimed = $value('claimed');

        return Reminder::query()
            ->where('remind_at', '>=', $from)
            ->where('remind_at', '<', $to)
            ->when($status !== '' && ReminderStatus::tryFrom($status) !== null, static fn (Builder $q) => $q->where('status', $status))
            ->when($reason !== '' && ReminderFailureReason::tryFrom($reason) !== null, static fn (Builder $q) => $q->where('last_error', $reason))
            ->when($channel !== '' && ChannelType::tryFrom($channel) !== null, static fn (Builder $q) => $q->where('channel', $channel))
            ->when($subscriber !== '' && ctype_digit($subscriber), static fn (Builder $q) => $q->where('user_id', (int) $subscriber))
            ->when($attempts !== '' && ctype_digit($attempts), static fn (Builder $q) => $q->where('attempts', '>=', (int) $attempts))
            // "Claimed but never dispatched" is the shape a crashed worker leaves.
            ->when($claimed === 'unsettled', static fn (Builder $q) => $q->whereNotNull('claimed_at')->whereNull('dispatched_at'))
            ->when($claimed === 'dispatched', static fn (Builder $q) => $q->whereNotNull('dispatched_at'));
    }

    /**
     * @param  Builder<Reminder>  $query
     * @return array{total: int, by_status: array<string, int>, by_reason: array<string, int>, attempts: int}
     */
    public static function totals(Builder $query): array
    {
        $byStatus = [];

        foreach ((clone $query)->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            $status = $row->getAttribute('status');
            $byStatus[$status instanceof ReminderStatus ? $status->value : (string) $status] = (int) $row->getAttribute('n');
        }

        $byReason = [];

        foreach ((clone $query)->whereNotNull('last_error')->selectRaw('last_error, count(*) as n')->groupBy('last_error')->get() as $row) {
            $byReason[(string) $row->getAttribute('last_error')] = (int) $row->getAttribute('n');
        }

        ksort($byStatus);
        ksort($byReason);

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'by_reason' => $byReason,
            // Physical provider attempts, which is NOT the number of reminders:
            // one occasion may cost up to two attempts, and the split matters.
            'attempts' => (int) (clone $query)->sum('attempts'),
        ];
    }

    /** A failure reason we recognise, or null for a legacy/unknown value. */
    public static function reasonLabel(?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return ReminderFailureReason::tryFrom($reason)?->label() ?? $reason;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertWindow(CarbonImmutable $from, CarbonImmutable $to, int $maxDays = self::MAX_DAYS): void
    {
        if ($to <= $from) {
            throw new InvalidArgumentException('نهاية النطاق يجب أن تكون بعد بدايته.');
        }

        if ($from->diffInDays($to) > $maxDays) {
            throw new InvalidArgumentException('النطاق الأقصى '.$maxDays.' يومًا.');
        }
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws InvalidArgumentException
     */
    public static function window(string $from, string $to): array
    {
        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                throw new InvalidArgumentException("التاريخ [{$name}] مطلوب بصيغة YYYY-MM-DD.");
            }
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d', $from)->startOfDay();
        $end = CarbonImmutable::createFromFormat('Y-m-d', $to)->startOfDay()->addDay();

        self::assertWindow($start, $end);

        return [$start, $end];
    }
}
