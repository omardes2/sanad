<?php

declare(strict_types=1);

namespace App\Data\Ai\Health;

use App\Enums\HealthCheckStatus;

/**
 * Outcome of one probe, SAFE by construction: status, latency, HTTP status,
 * an error class/code, small structured details (counts, booleans), and —
 * for a billable inference probe — the token usage to record in the ledger.
 * Never a body, never a URL with credentials, never a secret.
 *
 * @param  array<string, mixed>  $details
 */
final readonly class HealthProbeResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public HealthCheckStatus $status,
        public ?int $latencyMs = null,
        public ?int $httpStatus = null,
        public ?string $errorClass = null,
        public ?string $errorCode = null,
        public array $details = [],
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?string $reportedModel = null,
    ) {}
}
