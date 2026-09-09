<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Reminders\ReminderClaim;
use App\Models\Reminder;
use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Testing-only probe: ONE reminder-delivery step per process, one
 * machine-readable line, so the PostgreSQL races run in genuinely separate
 * processes with no shared transaction.
 *
 *   claim   <limit>       → claimed:<id>:<token>,…  |  claimed:
 *   deliver <id> <token>  → sent:<attempts> | dispatched:<…> | nosend:<status>
 *   sweep                 → swept:<recovered>:<failed>
 *   state   <id>          → <status>:<attempts>
 *
 * `deliver` takes the token because a worker with no claim identity could not
 * be fenced out — which is the whole point of the stale-worker race.
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

    public function handle(): int
    {
        // Configure BEFORE resolving anything: WhatsAppConfig is injected into
        // the policy, so a container resolution that happened first would carry
        // the environment's (disabled) channel configuration.
        $this->prepare();

        $dispatcher = app(ReminderDispatcher::class);

        /** @var list<string> $args */
        $args = (array) $this->argument('args');

        return match ((string) $this->argument('op')) {
            'claim' => $this->claim($dispatcher, (int) ($args[0] ?? 10)),
            'deliver' => $this->deliver($dispatcher, (int) ($args[0] ?? 0), (string) ($args[1] ?? '')),
            'sweep' => $this->sweep($dispatcher),
            'state' => $this->state((int) ($args[0] ?? 0)),
            default => self::FAILURE,
        };
    }

    private function claim(ReminderDispatcher $dispatcher, int $limit): int
    {
        $claims = array_map(
            static fn (ReminderClaim $c): string => $c->reminderId.':'.$c->token,
            $dispatcher->claimDue($limit),
        );

        $this->line('claimed:'.implode(',', $claims));

        return self::SUCCESS;
    }

    private function deliver(ReminderDispatcher $dispatcher, int $id, string $token): int
    {
        $dispatcher->deliver(new ReminderClaim($id, $token));
        $reminder = Reminder::query()->find($id);

        if ($reminder === null) {
            $this->line('missing');

            return self::SUCCESS;
        }

        // Whether THIS process sent is not inferred from the row — another
        // process may have moved it between reads. The faked client records
        // every request this process made, which is the authoritative fact.
        $requests = count(Http::recorded());

        $this->line(match (true) {
            $requests === 0 => 'nosend:'.$reminder->status->value,
            $reminder->status->value === 'sent' => 'sent:'.$reminder->attempts,
            $reminder->status->value === 'failed' => 'dispatched:failed:'.(string) $reminder->last_error,
            default => 'dispatched:'.$reminder->status->value,
        });

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

    /**
     * Deterministic settings and a faked provider: the race under test is the
     * claim, not the network, and a separate process must not depend on the
     * environment holding real WhatsApp credentials.
     */
    private function prepare(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.access_token' => 'PROBE_TOKEN',
            'whatsapp.phone_number_id' => 'PROBE_PNID',
            'whatsapp.graph_base_url' => 'https://graph.facebook.com',
            'whatsapp.graph_version' => 'v21.0',
            'reminders.enabled' => true,
            'reminders.max_lateness_minutes' => 600,
            'reminders.lease_seconds' => 300,
        ]);

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
