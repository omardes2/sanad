<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Exceptions\Tools\StaleToolConsentException;
use App\Exceptions\Tools\ToolRuleException;
use App\Services\Tools\ToolConsentService;
use Illuminate\Console\Command;

/**
 * Testing-only probe (Phase F1): ONE consent mutation, one machine-readable line.
 *
 *  grant  <subscriber_id> <capability> <expected_version>  → ok:<version> | stale | rejected:<rule>
 *  revoke <subscriber_id> <capability> <expected_version>  → ok:<version> | stale | rejected:<rule>
 *  state  <subscriber_id> <capability>                     → <status>:<version>
 */
class ToolConsentProbe extends Command
{
    protected $signature = 'sanad:tool-consent-probe {op} {args*}';

    protected $description = 'Testing only: perform one tool-consent mutation and print the outcome';

    protected $hidden = true;

    public function handle(ToolConsentService $consents): int
    {
        /** @var list<string> $a */
        $a = $this->argument('args');
        $capability = ToolCapability::from($a[1]);

        try {
            $line = match ((string) $this->argument('op')) {
                'grant' => 'ok:'.$consents->grant((int) $a[0], $capability, (int) $a[2], ToolConsentReason::SubscriberRequest, 'probe:consent')->version,
                'revoke' => 'ok:'.$consents->revoke((int) $a[0], $capability, (int) $a[2], ToolConsentReason::Security, 'probe:consent')->version,
                'state' => (fn ($s): string => $s->status.':'.$s->version)($consents->state((int) $a[0], $capability)),
                default => throw new \InvalidArgumentException('Unknown op'),
            };
        } catch (ToolRuleException $e) {
            $line = 'rejected:'.$e->rule;
        } catch (StaleToolConsentException) {
            $line = 'stale';
        }

        $this->line($line);

        return self::SUCCESS;
    }
}
