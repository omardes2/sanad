<?php

declare(strict_types=1);

namespace App\Services\Finance;

/**
 * Which cost components actually have a PRODUCER in the codebase — a code
 * fact, kept here so the coverage report can never claim a component is
 * "zero" when nothing records it at all.
 *
 *  - provider:      UsageRecorder + CostCalculator record every AI invocation.
 *  - communication: the calculator can cost WhatsApp dimensions, but NOTHING
 *                   records WhatsApp inbound/outbound rows in the ledger yet,
 *                   so communication_cost is absent, not zero.
 *  - external:      no producer at all.
 *
 * Flip a flag only together with the code that writes those rows.
 */
final class CostProducers
{
    public const PROVIDER = true;

    public const COMMUNICATION = false;

    public const EXTERNAL = false;
}
