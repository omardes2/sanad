<?php

declare(strict_types=1);

namespace App\Data\Ai\Catalog;

/**
 * The router's full reasoning for one operation: every candidate in the order
 * it was considered, why each one was skipped (or "selected"), and the chosen
 * spec. Produced by SanadAiRouter::evaluate() and reused unchanged by the
 * routing page and the enable/disable simulation (Phase C2), so what the admin
 * sees is exactly what the router does.
 *
 * @param  list<array{spec: ModelSpec, status: string, reason: ?string, estimate: ?float}>  $candidates
 */
final readonly class RouteEvaluation
{
    /**
     * @param  list<array{spec: ModelSpec, status: string, reason: ?string, estimate: ?float}>  $candidates
     */
    public function __construct(
        public string $preferredProvider,
        public array $candidates,
        public ?ModelSpec $selected,
    ) {}

    public function hasRoute(): bool
    {
        return $this->selected !== null;
    }

    public function selectedHandle(): ?string
    {
        return $this->selected === null ? null : $this->selected->provider.':'.$this->selected->model;
    }

    /**
     * @return list<ModelSpec>
     */
    public function eligible(): array
    {
        return array_values(array_map(
            static fn (array $row): ModelSpec => $row['spec'],
            array_filter($this->candidates, static fn (array $row): bool => in_array($row['status'], ['selected', 'eligible'], true)),
        ));
    }
}
