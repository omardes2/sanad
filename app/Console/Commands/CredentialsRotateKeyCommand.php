<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProviderCredential;
use App\Services\Audit\AuditLogger;
use App\Services\Credentials\CredentialVault;
use App\Support\Audit\AuditActions;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * MASTER-KEY rotation (Phase C3) — distinct from credential rotation:
 * the secret does not change, so the SAME row is re-encrypted with the current
 * CREDENTIALS_KEY (ciphertext + key_id updated in place). Rows are opened
 * with the previous keys (CREDENTIALS_PREVIOUS_KEYS). Dry run by default;
 * a row that cannot be opened is reported and left untouched. Audited.
 */
class CredentialsRotateKeyCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'sanad:credentials:rotate-key
        {--apply : Re-encrypt (default is a dry run)}
        {--force : Skip the production confirmation prompt}';

    protected $description = 'Re-encrypt provider credentials sealed by a previous master key with the current CREDENTIALS_KEY (dry run by default)';

    public function handle(CredentialVault $vault, AuditLogger $audit): int
    {
        if (! $vault->available()) {
            $this->error('The vault is unavailable: CREDENTIALS_KEY is not set or invalid.');

            return self::FAILURE;
        }

        $current = (string) $vault->keyId();
        $rows = ProviderCredential::query()->where('key_id', '!=', $current)->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $this->info("Every credential is already sealed by the current key [{$current}].");

            return self::SUCCESS;
        }

        $plan = [];

        foreach ($rows as $row) {
            $providerKey = (string) $row->provider()->value('key');
            $outcome = $vault->openCiphertext((string) $row->getAttribute('ciphertext'), $providerKey);
            $plan[] = ['row' => $row, 'provider' => $providerKey, 'ok' => $outcome->isOk(), 'reason' => $outcome->failure];
        }

        $this->table(['Credential', 'Provider', 'Status', 'From key', 'To key', 'Action'], array_map(static fn (array $p): array => [
            '#'.$p['row']->id.' '.$p['row']->fingerprint, $p['provider'], $p['row']->status->value, $p['row']->key_id, $current,
            $p['ok'] ? 're-encrypt' : 'SKIP ('.$p['reason'].')',
        ], $plan));

        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->comment('Dry run — nothing written (add --apply).');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('Application is in production — re-encrypt provider credentials?')) {
            return self::FAILURE;
        }

        $done = [];
        $skipped = [];

        DB::transaction(function () use ($plan, $vault, $audit, $current, &$done, &$skipped): void {
            foreach ($plan as $p) {
                /** @var ProviderCredential $row */
                $row = $p['row'];

                if (! $p['ok']) {
                    $skipped[] = ['id' => $row->id, 'reason' => $p['reason']];

                    continue;
                }

                $outcome = $vault->openCiphertext((string) $row->getAttribute('ciphertext'), $p['provider']);
                $sealed = $vault->seal($p['provider'], $outcome->secret);
                $from = $row->key_id;
                $row->forceFill(['ciphertext' => $sealed->ciphertext, 'key_id' => $sealed->keyId])->save();
                $done[] = ['id' => $row->id, 'from' => $from];
            }

            $audit->record(AuditActions::AiCredentialKeyRotated, null, [], [
                'to_key_id' => $current,
                'reencrypted' => $done,
                'skipped' => $skipped,
            ]);
        });

        $this->info(count($done).' credential(s) re-encrypted; '.count($skipped).' skipped.');

        return $skipped === [] ? self::SUCCESS : self::FAILURE;
    }
}
