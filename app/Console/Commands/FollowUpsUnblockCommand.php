<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FollowUpBlockReason;
use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Services\Reminders\ReminderDeliveryPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Releases follow-ups that were held because a dependency was missing — the
 * EXPLICIT, IDEMPOTENT recovery the blocked state is designed around.
 *
 * WHY RECOVERY IS AN OPERATOR ACTION AND NOT AUTOMATIC. A held loop means Sanad
 * wanted to ask and could not. If the hold lifted by itself the moment a template
 * appeared, every loop accumulated during an outage would ask at once, and the
 * subscriber would receive a burst of questions about things they may well have
 * finished days ago. Releasing is therefore a decision someone makes, and this
 * command makes it visible and repeatable.
 *
 * IT REFUSES TO RELEASE WHAT IS STILL BROKEN. A loop held for a missing template
 * is released only once an approved follow-up template is actually configured;
 * otherwise it stays held and the command says so. Running it twice changes
 * nothing the second time.
 *
 * Released loops become `open` with the next ask due NOW, which is deliberate:
 * the subscriber asked to be followed up with, and the ask budget and the
 * interval still bound everything that happens next.
 */
class FollowUpsUnblockCommand extends Command
{
    protected $signature = 'sanad:follow-ups:unblock
        {--reason= : only loops held for this reason ('.FollowUpBlockReason::TemplateUnavailable->value.'|'.FollowUpBlockReason::DeliveryUnavailable->value.')}
        {--id=* : only these follow-up ids}
        {--dry-run : report what would be released and change nothing}';

    protected $description = 'Release follow-ups held by a dependency that is now satisfied';

    public function handle(ReminderDeliveryPolicy $policy): int
    {
        $reason = $this->option('reason') === null
            ? null
            : FollowUpBlockReason::tryFrom((string) $this->option('reason'));

        if ($this->option('reason') !== null && $reason === null) {
            $this->error('Unknown block reason.');

            return self::FAILURE;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $dryRun = (bool) $this->option('dry-run');

        $held = FollowUp::query()
            ->where('status', FollowUpStatus::Blocked->value)
            ->when($reason !== null, fn ($q) => $q->where('blocked_reason', $reason->value))
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        if ($held->isEmpty()) {
            $this->info('No held follow-ups match.');

            return self::SUCCESS;
        }

        $templateReady = $policy->hasFollowUpTemplate();
        $released = 0;
        $stillHeld = 0;

        foreach ($held as $followUp) {
            // The dependency must ACTUALLY be satisfied. Releasing a loop whose
            // template is still missing would just recreate the same refusal.
            if ($followUp->blocked_reason === FollowUpBlockReason::TemplateUnavailable && ! $templateReady) {
                $stillHeld++;

                continue;
            }

            if ($dryRun) {
                $released++;

                continue;
            }

            $released += $this->release((int) $followUp->getKey());
        }

        $this->info(sprintf(
            '%s%d released · %d still held (dependency unsatisfied)',
            $dryRun ? '[dry run] ' : '',
            $released,
            $stillHeld,
        ));

        if ($released > 0 && ! $dryRun) {
            Log::info('sanad.follow_ups.unblocked', ['released' => $released]);
        }

        return self::SUCCESS;
    }

    /**
     * Idempotent by construction: the row is re-read under a lock and released
     * only if it is still held, so two runs cannot release the same loop twice and
     * a loop resolved in between is left alone.
     */
    private function release(int $id): int
    {
        return (int) DB::transaction(function () use ($id): int {
            /** @var FollowUp|null $fresh */
            $fresh = FollowUp::query()->whereKey($id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status !== FollowUpStatus::Blocked) {
                return 0;
            }

            $fresh->forceFill([
                'status' => FollowUpStatus::Open->value,
                'blocked_reason' => null,
                'blocked_at' => null,
                'next_ask_at' => CarbonImmutable::now('UTC'),
            ])->save();

            return 1;
        });
    }
}
