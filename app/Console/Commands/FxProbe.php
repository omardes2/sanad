<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Fx\RecordRateInput;
use App\Data\Fx\ReportingConversionInput;
use App\Exceptions\Fx\FxRuleException;
use App\Exceptions\Fx\StaleFxException;
use App\Services\Fx\FxPairBook;
use App\Services\Fx\FxRateBook;
use App\Services\Fx\ReportingConversionService;
use Illuminate\Console\Command;

/**
 * Testing-only probe (Phase E3): ONE FX operation, one machine-readable line.
 *
 *  create-pair <base> <quote>                       → ok:<id> | rejected:<rule>
 *  record-rate <base> <quote> <date> <rate> <expected|none> → ok:<id> | stale | rejected:<rule>
 *  convert <subject_type> <subject_id> <target> <fx_rate_id> <expected|none> → ok:<id> | stale | rejected:<rule>
 */
class FxProbe extends Command
{
    protected $signature = 'sanad:fx-probe {op} {args*}';

    protected $description = 'Testing only: perform one FX pair / rate / conversion operation and print the outcome';

    protected $hidden = true;

    public function handle(FxPairBook $pairs, FxRateBook $rates, ReportingConversionService $conversions): int
    {
        /** @var list<string> $a */
        $a = $this->argument('args');

        try {
            $line = match ((string) $this->argument('op')) {
                'create-pair' => 'ok:'.$pairs->create($a[0], $a[1])->id,
                'record-rate' => 'ok:'.$rates->record(new RecordRateInput(baseCurrency: $a[0], quoteCurrency: $a[1], rateDate: $a[2], rate: $a[3], evidenceRef: 'probe:'.$a[2], expectedCurrentRateId: $a[4] === 'none' ? null : (int) $a[4]))->id,
                'convert' => 'ok:'.$conversions->convert(new ReportingConversionInput(subjectType: $a[0], subjectId: (int) $a[1], targetCurrency: $a[2], fxRateId: (int) $a[3], expectedCurrentConversionId: $a[4] === 'none' ? null : (int) $a[4]))->id,
                default => throw new \InvalidArgumentException('Unknown op'),
            };
        } catch (FxRuleException $e) {
            $line = 'rejected:'.$e->rule;
        } catch (StaleFxException) {
            $line = 'stale';
        }

        $this->line($line);

        return self::SUCCESS;
    }
}
