<?php

declare(strict_types=1);

namespace App\Services\Launch;

use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;
use App\Data\Launch\LaunchGateStatus;
use App\Support\Launch\LaunchGate;
use App\Support\Launch\LaunchGateRegistry;
use Throwable;

/**
 * Evaluates every declared launch gate and sorts the answers into "blocks the
 * launch" and "does not".
 *
 * This service reads. It never writes a row, never calls an external service and
 * never touches a secret — a readiness screen that could change the thing it is
 * measuring would be a liability during exactly the week it matters most.
 *
 * A check that throws is caught and reported as `not_observed` rather than
 * crashing the page or, worse, being silently counted as ready. An unreadable
 * gate is an unknown gate, and unknown never blocks (see `LaunchGateState`).
 */
final class LaunchReadiness
{
    public function __construct(private readonly LaunchGateRegistry $registry) {}

    /**
     * @return list<LaunchGateStatus>
     */
    public function evaluate(): array
    {
        return $this->evaluateGates($this->registry->all());
    }

    /**
     * Evaluate an explicit gate list. `evaluate()` delegates here with the
     * registry's gates; callers that already hold a gate list (and the tests
     * that exercise a check which throws) use it directly, so the registry
     * itself stays `final` and has no test-only subclass.
     *
     * @param  list<LaunchGate>  $gates
     * @return list<LaunchGateStatus>
     */
    public function evaluateGates(array $gates): array
    {
        return array_map(
            fn (LaunchGate $gate): LaunchGateStatus => LaunchGateStatus::from($gate, $this->run($gate)),
            $gates,
        );
    }

    /**
     * The gates that stop V1 from launching, in registry order.
     *
     * @param  list<LaunchGateStatus>|null  $statuses  pass an evaluated list to avoid re-running every check
     * @return list<LaunchGateStatus>
     */
    public function blockers(?array $statuses = null): array
    {
        return array_values(array_filter(
            $statuses ?? $this->evaluate(),
            static fn (LaunchGateStatus $status): bool => $status->blocksLaunch(),
        ));
    }

    /**
     * A compact roll-up for the overview strip.
     *
     * @param  list<LaunchGateStatus>|null  $statuses
     * @return array{gates: int, required: int, ready: int, blockers: int, deferred: int}
     */
    public function summary(?array $statuses = null): array
    {
        $statuses ??= $this->evaluate();
        $required = array_values(array_filter($statuses, static fn (LaunchGateStatus $s): bool => $s->gate->requiredForV1));

        return [
            'gates' => count($statuses),
            'required' => count($required),
            'ready' => count(array_filter($required, static fn (LaunchGateStatus $s): bool => $s->state->isReady())),
            'blockers' => count(array_filter($statuses, static fn (LaunchGateStatus $s): bool => $s->blocksLaunch())),
            'deferred' => count(array_filter($statuses, static fn (LaunchGateStatus $s): bool => $s->isDeferred())),
        ];
    }

    private function run(LaunchGate $gate): GateOutcome
    {
        try {
            return $gate->evaluate();
        } catch (Throwable $e) {
            return GateOutcome::notObserved(
                'تعذّر تقييم هذا البند.',
                [GateDetail::unknown('الخطأ', class_basename($e))],
            );
        }
    }
}
