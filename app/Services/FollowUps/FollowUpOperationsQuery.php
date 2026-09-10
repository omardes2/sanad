<?php

declare(strict_types=1);

namespace App\Services\FollowUps;

use App\Enums\FollowUpBlockReason;
use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * OPERATIONAL METADATA ABOUT FOLLOW-UPS — AND NOT THE QUESTION TEXT.
 *
 * `follow_ups.question` is the subscriber's own words about something unfinished
 * in their life: a bill, a test result, a conversation with a relative. The
 * operational questions an admin actually has — why did Sanad ask twice, why is
 * this loop held, how much budget is left, when is the next ask — are all
 * answerable WITHOUT reading that sentence.
 *
 * So this is not a policy someone must remember: `SELECTS` below is the column
 * list every query here is restricted to, `question` is absent from it, and a test
 * asserts the column name never appears in any SQL this class produces. The split
 * mirrors `conversations.view` versus `messages.content.view`, which already draws
 * the same line between metadata and what a subscriber said.
 */
final class FollowUpOperationsQuery
{
    /**
     * The only columns any query in this class may read. `question` is absent on
     * purpose — see the class docblock.
     *
     * @var list<string>
     */
    public const SELECTS = [
        'id', 'user_id', 'task_id', 'channel', 'timezone', 'status', 'max_asks',
        'next_ask_at', 'resolved_at', 'terminated_at', 'blocked_reason', 'blocked_at',
        'version', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    public const FILTERS = ['subscriber_id', 'status', 'blocked_reason'];

    public const PER_PAGE = 20;

    /**
     * Platform-wide counts. `follow_ups` is bounded per subscriber by
     * `follow_ups.max_open_per_subscriber`, so the live side of these aggregates
     * stays proportional to the subscriber count rather than to message volume.
     *
     * @return array{live: int, blocked: int, awaiting: int, resolved: int, abandoned: int, cancelled: int, subscribers: int}
     */
    public static function overview(): array
    {
        $byStatus = [];

        foreach (FollowUp::query()->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            // The model casts `status` to its enum, so the grouped key is taken
            // from the case rather than stringified blindly.
            $status = $row->getAttribute('status');
            $key = $status instanceof FollowUpStatus ? $status->value : (string) $status;

            $byStatus[$key] = (int) $row->getAttribute('n');
        }

        $of = static fn (FollowUpStatus $status): int => $byStatus[$status->value] ?? 0;

        return [
            'live' => array_sum(array_map(
                static fn (string $value): int => $byStatus[$value] ?? 0,
                FollowUpStatus::liveValues(),
            )),
            'blocked' => $of(FollowUpStatus::Blocked),
            'awaiting' => $of(FollowUpStatus::AwaitingAnswer),
            'resolved' => $of(FollowUpStatus::ResolvedConfirmed) + $of(FollowUpStatus::ResolvedByTask),
            'abandoned' => $of(FollowUpStatus::Abandoned),
            'cancelled' => $of(FollowUpStatus::Cancelled),
            'subscribers' => (int) FollowUp::query()->distinct()->count('user_id'),
        ];
    }

    /**
     * The bounded listing.
     *
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, FollowUp>
     */
    public static function paginate(array $filters): LengthAwarePaginator
    {
        return self::build($filters)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @param  array<string, string>  $filters
     * @return Builder<FollowUp>
     */
    public static function build(array $filters): Builder
    {
        // The select list is applied HERE, so every path through this class is
        // restricted by construction rather than by remembering to restrict it.
        $query = FollowUp::query()->select(self::SELECTS);

        $subscriber = trim($filters['subscriber_id'] ?? '');

        if ($subscriber !== '' && ctype_digit($subscriber)) {
            $query->where('user_id', (int) $subscriber);
        }

        $status = trim($filters['status'] ?? '');

        if ($status !== '' && FollowUpStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $reason = trim($filters['blocked_reason'] ?? '');

        if ($reason !== '' && FollowUpBlockReason::tryFrom($reason) !== null) {
            $query->where('blocked_reason', $reason);
        }

        return $query;
    }
}
