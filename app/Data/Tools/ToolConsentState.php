<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Enums\ToolCapability;
use App\Enums\ToolConsentStatus;
use Carbon\CarbonImmutable;

/**
 * What the platform knows about one (subscriber, capability) right now (Phase
 * F1) — including the case that matters most: NO ROW.
 *
 * `status` is the string `not_granted` when nothing was ever decided, and the
 * enum value otherwise; `version` is 0 in that case, which is exactly what a
 * first grant must state as the version it saw. There is no other way to read
 * consent, so no caller can mistake "unknown" for "granted".
 */
final readonly class ToolConsentState
{
    private function __construct(
        public int $subscriberId,
        public ToolCapability $capability,
        public string $status,
        public int $version,
        public ?CarbonImmutable $grantedAt,
        public ?CarbonImmutable $revokedAt,
    ) {}

    public static function notGranted(int $subscriberId, ToolCapability $capability): self
    {
        return new self($subscriberId, $capability, ToolConsentStatus::NOT_GRANTED, 0, null, null);
    }

    public static function of(int $subscriberId, ToolCapability $capability, ToolConsentStatus $status, int $version, ?CarbonImmutable $grantedAt, ?CarbonImmutable $revokedAt): self
    {
        return new self($subscriberId, $capability, $status->value, $version, $grantedAt, $revokedAt);
    }

    public function granted(): bool
    {
        return $this->status === ToolConsentStatus::Granted->value;
    }

    /**
     * @return array<string, mixed> bounded facts only — no name, no email, no phone
     */
    public function describe(): array
    {
        return [
            'subscriber_id' => $this->subscriberId,
            'capability' => $this->capability->value,
            'status' => $this->status,
            'version' => $this->version,
            'granted_at' => $this->grantedAt?->utc()->format('Y-m-d H:i:s'),
            'revoked_at' => $this->revokedAt?->utc()->format('Y-m-d H:i:s'),
        ];
    }
}
