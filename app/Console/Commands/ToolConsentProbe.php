<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Exceptions\Tools\StaleToolConsentException;
use App\Exceptions\Tools\ToolRuleException;
use App\Services\Tools\ToolConsentService;
use App\Support\Tools\EvidenceRef;
use App\Support\Tools\ToolAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Testing-only probe (Phase F1): ONE consent mutation, one machine-readable line.
 *
 *  grant  <subscriber_id> <capability> <expected_version>
 *      → signs in AS THE SUBSCRIBER (the only actor allowed to grant) and grants.
 *  revoke <subscriber_id> <capability> <expected_version> [self|console]
 *      → self (default): signs in as the subscriber; console: runs inside the
 *        named console-administrator scope, which may revoke and never grant.
 *  state  <subscriber_id> <capability>       → <status>:<version>
 *
 *  refused-grant <subscriber_id> <capability>  → the console path attempting a
 *      grant, to prove it is refused rather than silently allowed.
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
        $op = (string) $this->argument('op');
        $subscriberId = (int) $a[0];
        $capability = ToolCapability::from($a[1]);
        $evidence = EvidenceRef::of('policy:probe');

        try {
            $line = match ($op) {
                'grant' => (function () use ($consents, $subscriberId, $capability, $a, $evidence): string {
                    Auth::loginUsingId($subscriberId); // the subscriber's own authenticated action

                    return 'ok:'.$consents->grant($subscriberId, $capability, (int) $a[2], ToolConsentReason::SubscriberRequest, $evidence)->version;
                })(),
                'revoke' => (function () use ($consents, $subscriberId, $capability, $a, $evidence): string {
                    $reason = ToolConsentReason::Security;

                    if (($a[3] ?? 'self') === 'console') {
                        return 'ok:'.ToolAuthorization::asConsoleAdministrator('ops.consent-revocation', fn () => $consents->revoke($subscriberId, $capability, (int) $a[2], $reason, $evidence))->version;
                    }

                    Auth::loginUsingId($subscriberId);

                    return 'ok:'.$consents->revoke($subscriberId, $capability, (int) $a[2], $reason, $evidence)->version;
                })(),
                'refused-grant' => (function () use ($consents, $subscriberId, $capability, $evidence): string {
                    // No authenticated user and no scope may create consent — not even an administrator.
                    ToolAuthorization::asConsoleAdministrator('ops.consent-revocation', fn () => $consents->grant($subscriberId, $capability, 0, ToolConsentReason::OperatorRequest, $evidence));

                    return 'granted';
                })(),
                'state' => (fn ($s): string => $s->status.':'.$s->version)($consents->state($subscriberId, $capability)),
                default => throw new \InvalidArgumentException('Unknown op'),
            };
        } catch (AuthorizationException) {
            $line = 'forbidden';
        } catch (ToolRuleException $e) {
            $line = 'rejected:'.$e->rule;
        } catch (StaleToolConsentException) {
            $line = 'stale';
        }

        $this->line($line);

        return self::SUCCESS;
    }
}
