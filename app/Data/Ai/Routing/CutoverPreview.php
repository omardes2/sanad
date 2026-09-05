<?php

declare(strict_types=1);

namespace App\Data\Ai\Routing;

use App\Data\Ai\Catalog\RouteEvaluation;

/**
 * Everything an admin sees before confirming a cutover (Phase C4), and
 * exactly what the apply step re-checks inside its transaction:
 *  - the state the admin saw (`currentValue`, for the stale-conflict check);
 *  - the live and the post-cutover route evaluations;
 *  - readiness of the provider/model that would serve chat afterwards;
 *  - the blockers (empty = can be applied) and warnings;
 *  - the typed confirmation expected: the `provider:model` the router would
 *    select after the change — never a provider name alone.
 *
 * @param  list<string>  $blockers
 * @param  list<string>  $warnings
 */
final readonly class CutoverPreview
{
    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $kind,
        public string $currentValue,
        public string $targetValue,
        public RouteEvaluation $before,
        public RouteEvaluation $after,
        public ?ReadinessReport $readiness,
        public array $blockers,
        public array $warnings,
        public bool $sameRouteRequired,
    ) {}

    public function sameRoute(): bool
    {
        return $this->before->selectedHandle() === $this->after->selectedHandle();
    }

    public function expectedConfirmation(): string
    {
        return (string) $this->after->selectedHandle();
    }

    public function applicable(): bool
    {
        return $this->blockers === [] && $this->after->hasRoute();
    }
}
