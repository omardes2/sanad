<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Enums\FollowUpAnswer;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\User;
use App\Services\FollowUps\FollowUpAskMaterialiser;
use App\Services\FollowUps\FollowUpService;
use App\Services\Tasks\TaskService;
use App\Services\Tools\ToolExecutor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Testing-only probe: ONE follow-up step per process, one machine-readable line,
 * so the PostgreSQL races run in genuinely separate processes with real row locks
 * and no shared transaction.
 *
 *   create   <userId> <messageId>        → created:<id>  |  refused:<Class>
 *   advance  <followUpId>                → <outcome>  |  threw:<Class>
 *   resolve  <followUpId> <messageId> <outcome>
 *                                        → resolved:<0|1>:<status>  |  refused:<Class>
 *   cancel   <followUpId> <userId>       → cancelled:<asks>:<terminated>  |  refused:<Class>
 *   complete <userId> <taskId>           → completed  |  refused:<Class>
 *   state    <followUpId>                → <status>:<asks>:<asksUsed>
 *
 * `advance` targets ONE loop rather than running the whole sweep, because the race
 * under test is two processes advancing the SAME loop — a batch walk would let
 * them drift onto different rows and prove nothing.
 */
class FollowUpProbe extends Command
{
    protected $signature = 'sanad:follow-up-probe {op} {args?*} {--sleep=0} {--template=1}';

    protected $description = 'Testing only: perform one follow-up step and print the outcome';

    protected $hidden = true;

    public function handle(): int
    {
        $this->prepare();

        /** @var list<string> $args */
        $args = (array) $this->argument('args');

        return match ((string) $this->argument('op')) {
            'create' => $this->create((int) ($args[0] ?? 0), (int) ($args[1] ?? 0)),
            // Through the REAL tool path, so a replay is fenced by the invocation
            // slot exactly as it is in production.
            'tool' => $this->tool((int) ($args[0] ?? 0)),
            'advance' => $this->advance((int) ($args[0] ?? 0)),
            'resolve' => $this->resolve((int) ($args[0] ?? 0), (int) ($args[1] ?? 0), (string) ($args[2] ?? 'confirmed')),
            'cancel' => $this->cancel((int) ($args[0] ?? 0), (int) ($args[1] ?? 0)),
            'complete' => $this->completeTask((int) ($args[0] ?? 0), (int) ($args[1] ?? 0)),
            'state' => $this->state((int) ($args[0] ?? 0)),
            default => self::FAILURE,
        };
    }

    private function create(int $userId, int $messageId): int
    {
        $this->stagger();

        $user = User::query()->find($userId);
        $message = Message::query()->find($messageId);

        if ($user === null || $message === null) {
            $this->line('refused:NoSubject');

            return self::SUCCESS;
        }

        try {
            $created = app(FollowUpService::class)->create(
                $user,
                [
                    'question' => 'دفعت فاتورة الكهربا؟',
                    'first_ask_at' => now('UTC')->addHours(5)->format('Y-m-d\TH:i'),
                ],
                ChannelType::WhatsApp,
                $message,
            );

            $this->line('created:'.$created['follow_up_id']);
        } catch (Throwable $e) {
            $this->line('refused:'.class_basename($e));
        }

        return self::SUCCESS;
    }

    /**
     * Open a loop through `ToolExecutor` — the production path, including the
     * invocation slot.
     *
     * This is how the replay property is proven rather than asserted: the slot is
     * `(message, tool, canonical input)` and it is UNIQUE, so many processes asking
     * for the same call produce ONE execution and therefore one loop. Re-deriving
     * that guarantee inside the follow-up domain would be a second, weaker copy of
     * a fence the platform already owns.
     */
    private function tool(int $messageId): int
    {
        $this->stagger();

        $message = Message::query()->find($messageId);

        if ($message === null) {
            $this->line('refused:NoSubject');

            return self::SUCCESS;
        }

        try {
            $result = app(ToolExecutor::class)->call($message, 'follow_up.create@1', [
                'question' => 'دفعت فاتورة الكهربا؟',
                'first_ask_at' => now('UTC')->addHours(5)->format('Y-m-d\TH:i'),
            ]);

            if ($result->invocation === null) {
                $this->line('refused:'.($result->refusal?->value ?? 'unknown'));

                return self::SUCCESS;
            }

            $this->line(($result->claim?->value ?? 'none').':'.$result->status->value);
        } catch (Throwable $e) {
            $this->line('refused:'.class_basename($e));
        }

        return self::SUCCESS;
    }

    private function advance(int $followUpId): int
    {
        $this->stagger();

        $followUp = FollowUp::query()->find($followUpId);

        if ($followUp === null) {
            $this->line('missing');

            return self::SUCCESS;
        }

        try {
            $this->line(app(FollowUpAskMaterialiser::class)->advance($followUp));
        } catch (Throwable $e) {
            $this->line('threw:'.class_basename($e));
        }

        return self::SUCCESS;
    }

    private function resolve(int $followUpId, int $messageId, string $outcome): int
    {
        $this->stagger();

        $followUp = FollowUp::query()->find($followUpId);
        $message = Message::query()->find($messageId);

        if ($followUp === null || $message === null) {
            $this->line('refused:NoSubject');

            return self::SUCCESS;
        }

        try {
            $result = app(FollowUpService::class)->resolve(
                $followUp->user,
                $followUpId,
                FollowUpAnswer::from($outcome),
                $message,
            );

            $this->line('resolved:'.(int) $result['resolved'].':'.$result['status']);
        } catch (Throwable $e) {
            $this->line('refused:'.class_basename($e));
        }

        return self::SUCCESS;
    }

    private function cancel(int $followUpId, int $userId): int
    {
        $this->stagger();

        $user = User::query()->find($userId);

        if ($user === null) {
            $this->line('refused:NoSubject');

            return self::SUCCESS;
        }

        try {
            $result = app(FollowUpService::class)->cancel($user, $followUpId);

            $this->line('cancelled:'.$result['cancelled_asks'].':'.(int) $result['terminated']);
        } catch (Throwable $e) {
            $this->line('refused:'.class_basename($e));
        }

        return self::SUCCESS;
    }

    /** Named `completeTask` because Symfony's Command::complete() is public and final in spirit. */
    private function completeTask(int $userId, int $taskId): int
    {
        $this->stagger();

        $user = User::query()->find($userId);

        if ($user === null) {
            $this->line('refused:NoSubject');

            return self::SUCCESS;
        }

        try {
            app(TaskService::class)->complete($user, $taskId);
            $this->line('completed');
        } catch (Throwable $e) {
            $this->line('refused:'.class_basename($e));
        }

        return self::SUCCESS;
    }

    private function state(int $followUpId): int
    {
        $followUp = FollowUp::query()->find($followUpId);

        if ($followUp === null) {
            $this->line('missing');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s:%d:%d',
            $followUp->status->value,
            Reminder::query()->where('follow_up_id', $followUpId)->count(),
            $followUp->asksUsed(),
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
        $template = (bool) (int) $this->option('template');

        config([
            // ONE STEP PER PROCESS: under PHPUnit the parent exports
            // QUEUE_CONNECTION=sync and a child inherits it, which would run
            // dispatched work inline and make a test about the loop silently a
            // test about delivery too.
            'queue.default' => 'null',
            'follow_ups.enabled' => true,
            'follow_ups.max_open_per_subscriber' => 5,
            'follow_ups.max_asks_per_follow_up' => 3,
            'follow_ups.min_ask_interval_hours' => 24,
            'follow_ups.answer_window_hours' => 72,
            // `--template=0` is how a test makes the external dependency missing in
            // the CHILD process, which is where the decision is actually made.
            'follow_ups.whatsapp.template.ready' => $template,
            'follow_ups.whatsapp.template.name' => $template ? 'sanad_follow_up_v1' : '',
            'follow_ups.whatsapp.template.language' => 'ar',
            'reminders.enabled' => true,
            'reminders.max_lateness_minutes' => 600,
            'reminders.whatsapp.free_form_window_hours' => 24,
            'whatsapp.enabled' => true,
            'whatsapp.access_token' => 'PROBE_TOKEN',
            'whatsapp.phone_number_id' => 'PROBE_PNID',
            'whatsapp.graph_base_url' => 'https://graph.facebook.com',
            'whatsapp.graph_version' => 'v21.0',
        ]);
    }
}
