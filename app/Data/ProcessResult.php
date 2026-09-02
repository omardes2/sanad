<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ProcessOutcome;
use App\Models\Message;

/**
 * Outcome of MessageProcessor::process(): a clear enum plus the inbound
 * message row it resolved to (the new one, or the pre-existing duplicate).
 */
final readonly class ProcessResult
{
    public function __construct(
        public ProcessOutcome $outcome,
        public ?Message $message = null,
    ) {}

    public function accepted(): bool
    {
        return $this->outcome === ProcessOutcome::Accepted;
    }

    public function duplicate(): bool
    {
        return $this->outcome === ProcessOutcome::Duplicate;
    }

    public function rejected(): bool
    {
        return $this->outcome === ProcessOutcome::Rejected;
    }
}
