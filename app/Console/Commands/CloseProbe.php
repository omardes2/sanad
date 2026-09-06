<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Close\CloseBlockedException;
use App\Exceptions\Close\CloseRuleException;
use App\Exceptions\Close\StaleCloseException;
use App\Services\Close\PeriodCloseService;
use Illuminate\Console\Command;

/**
 * Testing-only probe (Phase E4): ONE close / reopen operation, one line.
 *
 *  close <YYYY-MM> <expected|none> <idempotency_key>       → ok:<id> | stale | blocked:<codes> | rejected:<rule>
 *  reopen <close_id> <expected|none> <YYYY-MM>              → ok:<id> | stale | rejected:<rule>
 */
class CloseProbe extends Command
{
    protected $signature = 'sanad:close-probe {op} {args*}';

    protected $description = 'Testing only: perform one period close / reopen and print the outcome';

    protected $hidden = true;

    public function handle(PeriodCloseService $service): int
    {
        /** @var list<string> $a */
        $a = $this->argument('args');

        try {
            $line = match ((string) $this->argument('op')) {
                'close' => 'ok:'.$service->close($a[0], $a[1] === 'none' ? null : (int) $a[1], $a[2], 'CLOSE '.$a[0])->id,
                'reopen' => 'ok:'.$service->reopen((int) $a[0], $a[1] === 'none' ? null : (int) $a[1], 'probe', 'probe:reopen', 'REOPEN '.$a[2])->id,
                default => throw new \InvalidArgumentException('Unknown op'),
            };
        } catch (CloseRuleException $e) {
            $line = 'rejected:'.$e->rule;
        } catch (CloseBlockedException $e) {
            $line = 'blocked:'.implode('|', array_map(static fn (string $c): string => explode(' ', $c)[0], $e->conditions));
        } catch (StaleCloseException) {
            $line = 'stale';
        }

        $this->line($line);

        return self::SUCCESS;
    }
}
