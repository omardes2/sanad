<?php

declare(strict_types=1);

namespace App\Data\Ai\Routing;

final readonly class ReadinessCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        public string $key,
        public string $status,
        public string $label,
        public string $detail,
    ) {}

    /**
     * @return array{key: string, status: string, label: string, detail: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'status' => $this->status, 'label' => $this->label, 'detail' => $this->detail];
    }
}
