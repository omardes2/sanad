<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Credentials\CredentialLifecycleException;
use App\Models\ProviderCredential;
use App\Services\Credentials\CredentialManager;
use Illuminate\Console\Command;

/**
 * Testing-only probe: activates ONE credential through CredentialManager and
 * prints "activated" or "rejected". Launched as many concurrent OS processes
 * by the real PostgreSQL concurrency test to prove the provider-row lock
 * serialises activations (exactly one active row survives). Not for
 * production use.
 */
class AiCredentialActivateProbe extends Command
{
    protected $signature = 'sanad:ai-credential-probe {credential} {expected=none : the active credential id the caller saw, or "none"}';

    protected $description = 'Testing only: activate one credential and print the outcome';

    protected $hidden = true;

    public function handle(CredentialManager $manager): int
    {
        $credential = ProviderCredential::query()->find((int) $this->argument('credential'));

        if ($credential === null) {
            $this->line('missing');

            return self::FAILURE;
        }

        try {
            $expected = (string) $this->argument('expected');
            $manager->activate($credential, $expected === 'none' ? null : (int) $expected);
            $this->line('activated');
        } catch (CredentialLifecycleException) {
            $this->line('rejected');
        }

        return self::SUCCESS;
    }
}
