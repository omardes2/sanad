<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Routing\CutoverBlockedException;
use App\Exceptions\Routing\StaleCutoverException;
use App\Models\AiProvider;
use App\Services\Ai\Routing\RoutingCutover;
use Illuminate\Console\Command;

/**
 * Testing-only probe: performs ONE cutover (routing mode or primary) through
 * RoutingCutover and prints "applied", "stale" or "blocked". Launched as many
 * concurrent OS processes by the real PostgreSQL concurrency tests to prove
 * that concurrent cutovers from the same previewed state have exactly one
 * winner. Not for production use.
 */
class AiCutoverProbe extends Command
{
    protected $signature = 'sanad:ai-cutover-probe {action : mode|primary} {target} {expected} {confirmation}';

    protected $description = 'Testing only: apply one routing cutover and print the outcome';

    protected $hidden = true;

    public function handle(RoutingCutover $cutover): int
    {
        $expected = (string) $this->argument('expected');

        try {
            if ($this->argument('action') === 'primary') {
                $provider = AiProvider::query()->findOrFail((int) $this->argument('target'));
                $cutover->setPrimary($provider, $expected === 'none' ? null : (int) $expected, (string) $this->argument('confirmation'));
            } else {
                $cutover->switchRoutingMode((string) $this->argument('target'), $expected, (string) $this->argument('confirmation'));
            }

            $this->line('applied');
        } catch (StaleCutoverException) {
            $this->line('stale');
        } catch (CutoverBlockedException) {
            $this->line('blocked');
        }

        return self::SUCCESS;
    }
}
