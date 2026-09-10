<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Voice\VoiceTranscriptionSweeper;
use Illuminate\Console\Command;

class VoiceTranscriptionSweepCommand extends Command
{
    protected $signature = 'sanad:voice:sweep';

    protected $description = 'Re-queue voice notes stuck past their transcription claim lease';

    public function handle(VoiceTranscriptionSweeper $sweeper): int
    {
        $result = $sweeper->sweep();

        $this->info(sprintf('Re-queued %d voice note(s) for transcription.', $result['recovered']));

        return self::SUCCESS;
    }
}
