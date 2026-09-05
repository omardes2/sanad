<?php

declare(strict_types=1);

namespace App\Data\Ai\Routing;

/**
 * Provider / model / credential / health / pricing / catalog readiness for a
 * cutover target (Phase C4). `fail` blocks; `warn` is shown (e.g. COST
 * UNKNOWN) but does not block.
 *
 * @param  list<ReadinessCheck>  $checks
 */
final readonly class ReadinessReport
{
    /**
     * @param  list<ReadinessCheck>  $checks
     */
    public function __construct(
        public string $provider,
        public ?string $model,
        public array $checks,
    ) {}

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        return array_values(array_map(
            static fn (ReadinessCheck $c): string => $c->label.': '.$c->detail,
            array_filter($this->checks, static fn (ReadinessCheck $c): bool => $c->status === ReadinessCheck::FAIL),
        ));
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_map(
            static fn (ReadinessCheck $c): string => $c->label.': '.$c->detail,
            array_filter($this->checks, static fn (ReadinessCheck $c): bool => $c->status === ReadinessCheck::WARN),
        ));
    }

    public function passes(): bool
    {
        return $this->failures() === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'checks' => array_map(static fn (ReadinessCheck $c): array => $c->toArray(), $this->checks),
        ];
    }
}
