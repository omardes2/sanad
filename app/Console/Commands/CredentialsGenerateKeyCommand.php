<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

/**
 * Prints a fresh CREDENTIALS_KEY (base64 of 32 random bytes for AES-256-GCM).
 * It writes NOTHING: the operator places the key in the environment after the
 * vault cutover is approved.
 */
class CredentialsGenerateKeyCommand extends Command
{
    protected $signature = 'sanad:credentials:generate-key';

    protected $description = 'Print a new CREDENTIALS_KEY (writes nothing)';

    public function handle(): int
    {
        $this->line('base64:'.base64_encode(Encrypter::generateKey((string) config('credentials.cipher', 'aes-256-gcm'))));

        return self::SUCCESS;
    }
}
