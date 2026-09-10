<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Enums\ReminderCancelScope;
use App\Models\Reminder;
use App\Models\ReminderSchedule;
use App\Models\User;
use App\Services\Reminders\ReminderMaterialiser;
use App\Services\Reminders\ReminderScheduleService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Testing-only probe: ONE recurrence step per process, one machine-readable line,
 * so the PostgreSQL races run in genuinely separate processes with real row locks
 * and no shared transaction.
 *
 *   materialise <scheduleId>          → created:<n>  |  threw:<Class>
 *   cancel      <scheduleId> <scope>  → cancelled:<n>:<terminated>
 *   create      <userId>              → created:<scheduleId>  |  refused:<Class>
 *   count       <scheduleId>          → <occurrences>:<status>
 *
 * `materialise` targets ONE schedule rather than running the whole sweep, because
 * the race under test is two processes materialising the SAME series — a batch
 * walk would let them drift onto different rows and prove nothing.
 */
class ReminderRecurrenceProbe extends Command
{
    protected $signature = 'sanad:reminder-recurrence-probe {op} {args?*} {--sleep=0} {--horizon=30}';

    protected $description = 'Testing only: perform one recurring-reminder step and print the outcome';

    protected $hidden = true;

    public function handle(): int
    {
        $this->prepare();

        /** @var list<string> $args */
        $args = (array) $this->argument('args');

        return match ((string) $this->argument('op')) {
            'materialise' => $this->materialise((int) ($args[0] ?? 0)),
            'cancel' => $this->cancel((int) ($args[0] ?? 0), (string) ($args[1] ?? 'series')),
            'create' => $this->create((int) ($args[0] ?? 0)),
            'count' => $this->count((int) ($args[0] ?? 0)),
            default => self::FAILURE,
        };
    }

    private function materialise(int $scheduleId): int
    {
        $this->stagger();

        $schedule = ReminderSchedule::query()->find($scheduleId);

        if ($schedule === null) {
            $this->line('created:0');

            return self::SUCCESS;
        }

        try {
            $this->line('created:'.app(ReminderMaterialiser::class)->materialise($schedule));
        } catch (Throwable $e) {
            $this->line('threw:'.class_basename($e));
        }

        return self::SUCCESS;
    }

    private function cancel(int $scheduleId, string $scope): int
    {
        $this->stagger();

        $schedule = ReminderSchedule::query()->find($scheduleId);

        if ($schedule === null) {
            $this->line('cancelled:0:0');

            return self::SUCCESS;
        }

        try {
            $result = app(ReminderScheduleService::class)->cancel(
                $schedule->user,
                $scheduleId,
                ReminderCancelScope::from($scope),
            );

            $this->line('cancelled:'.$result['cancelled_occurrences'].':'.(int) $result['terminated']);
        } catch (Throwable $e) {
            $this->line('threw:'.class_basename($e));
        }

        return self::SUCCESS;
    }

    private function create(int $userId): int
    {
        $this->stagger();

        $user = User::query()->find($userId);

        if ($user === null) {
            $this->line('refused:NoUser');

            return self::SUCCESS;
        }

        try {
            $created = app(ReminderScheduleService::class)->create(
                $user,
                ['title' => 'اشرب الدوا', 'pattern' => 'daily', 'local_time' => '09:00'],
                ChannelType::WhatsApp,
            );

            $this->line('created:'.$created['schedule_id']);
        } catch (Throwable $e) {
            $this->line('refused:'.class_basename($e));
        }

        return self::SUCCESS;
    }

    private function count(int $scheduleId): int
    {
        $schedule = ReminderSchedule::query()->find($scheduleId);

        $this->line(sprintf(
            '%d:%s',
            Reminder::query()->where('reminder_schedule_id', $scheduleId)->count(),
            $schedule?->status->value ?? 'missing',
        ));

        return self::SUCCESS;
    }

    /** A deliberate stagger, so several processes are inside the step at once. */
    private function stagger(): void
    {
        if (($micro = (int) $this->option('sleep')) > 0) {
            usleep($micro);
        }
    }

    private function prepare(): void
    {
        config([
            // ONE STEP PER PROCESS: under PHPUnit the parent exports
            // QUEUE_CONNECTION=sync and a child inherits it, which would run
            // dispatched work inline and make a test about materialisation
            // silently a test about delivery too.
            'queue.default' => 'null',
            'reminders.enabled' => true,
            'reminders.recurrence.enabled' => true,
            // The horizon is an OPTION so a test can make the occurrence count
            // bound the binding one: the default 30-day window and a daily series
            // are the same number, which would prove nothing about the cap.
            'reminders.recurrence.horizon_days' => max(1, (int) $this->option('horizon')),
            'reminders.recurrence.max_occurrences_per_schedule' => 35,
            'reminders.recurrence.max_active_schedules_per_subscriber' => 10,
            'whatsapp.enabled' => true,
            'whatsapp.access_token' => 'PROBE_TOKEN',
            'whatsapp.phone_number_id' => 'PROBE_PNID',
            'whatsapp.graph_base_url' => 'https://graph.facebook.com',
            'whatsapp.graph_version' => 'v21.0',
        ]);
    }
}
