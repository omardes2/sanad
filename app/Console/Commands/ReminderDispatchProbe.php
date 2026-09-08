<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Testing-only probe: ONE reminder-delivery step per process, one
 * machine-readable line, so the PostgreSQL races run in genuinely separate
 * processes with no shared transaction.
 *
 *   claim   <limit>   → claimed:<id>,<id>,…  |  claimed:
 *   deliver <id>      → sent:<attempts> | processing:<attempts> | failed:<reason> | skipped:<attempts>
 *   sweep             → swept:<recovered>:<failed>
 *   state   <id>      → <status>:<attempts>
 *
 * The provider call is FAKED here: this probe exercises the claim/dispatch
 * concurrency, never a live network. `--outcome` chooses what the fake provider
 * answers, so a race can be run against an accepted send or an unproven one.
 */
class ReminderDispatchProbe extends Command
{
    protected $signature = 'sanad:reminder-dispatch-probe {op} {args?*} {--outcome=accepted}';

    protected $description = 'Testing only: perform one reminder-delivery step and print the outcome';

    protected $hidden = true;

    public function handle(ReminderDispatcher $dispatcher): int
    {
        /** @var list<string> $args */
        $args = (array) $this->argument('args');

        return match ((string) $this->argument('op')) {
            'claim' => $this->claim($dispatcher, (int) ($args[0] ?? 10)),
            'deliver' => $this->deliver($dispatcher, (int) ($args[0] ?? 0)),
            'sweep' => $this->sweep($dispatcher),
            'state' => $this->state((int) ($args[0] ?? 0)),
            default => self::FAILURE,
        };
    }

    private function claim(ReminderDispatcher $dispatcher, int $limit): int
    {
        $this->line('claimed:'.implode(',', $dispatcher->claimDue($limit)));

        return self::SUCCESS;
    }

    private function deliver(ReminderDispatcher $dispatcher, int $id): int
    {
        $this->fakeProvider();

        $before = Reminder::query()->find($id)?->attempts ?? 0;
        $dispatcher->deliver($id);
        $reminder = Reminder::query()->find($id);

        if ($reminder === null) {
            $this->line('missing');

            return self::SUCCESS;
        }

        // "skipped" = this process was not allowed to dispatch: no request left
        // it, and the attempt counter is untouched.
        $line = $reminder->attempts === $before
            ? 'skipped:'.$reminder->attempts
            : match ($reminder->status->value) {
                'sent' => 'sent:'.$reminder->attempts,
                'failed' => 'failed:'.(string) $reminder->last_error,
                default => 'processing:'.$reminder->attempts,
            };

        $this->line($line);

        return self::SUCCESS;
    }

    private function sweep(ReminderDispatcher $dispatcher): int
    {
        $result = $dispatcher->sweep();
        $this->line("swept:{$result['recovered']}:{$result['failed']}");

        return self::SUCCESS;
    }

    private function state(int $id): int
    {
        $reminder = Reminder::query()->find($id);

        $this->line($reminder === null ? 'missing' : $reminder->status->value.':'.$reminder->attempts);

        return self::SUCCESS;
    }

    /** Never a live call: the race under test is the claim, not the network. */
    private function fakeProvider(): void
    {
        Http::fake([
            'graph.facebook.com/*' => match ((string) $this->option('outcome')) {
                // 5xx: the provider may have accepted before failing to answer,
                // so the outcome is unproven — exactly the retry-budget case.
                'unknown' => Http::response(['error' => ['message' => 'boom']], 500),
                'rejected' => Http::response(['error' => ['message' => 'nope']], 400),
                default => Http::response(['messages' => [['id' => 'wamid.PROBE'.bin2hex(random_bytes(6))]]], 200),
            },
        ]);
    }
}
