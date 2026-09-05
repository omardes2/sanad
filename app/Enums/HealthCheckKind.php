<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a provider health check probes (Phase C3, decision C):
 *  - connectivity: the endpoint answers over TLS (no credential sent);
 *  - auth: a NON-billable authenticated probe the adapter explicitly declares
 *    it supports (never assumed); proves the credential is accepted;
 *  - inference: one minimal completion — BILLABLE, manual only, typed
 *    confirmation, recorded in the usage ledger.
 */
enum HealthCheckKind: string
{
    case Connectivity = 'connectivity';
    case Auth = 'auth';
    case Inference = 'inference';

    public function billable(): bool
    {
        return $this === self::Inference;
    }

    public function label(): string
    {
        return match ($this) {
            self::Connectivity => 'الاتصال',
            self::Auth => 'المصادقة (غير مفوتر)',
            self::Inference => 'استدلال (مفوتر)',
        };
    }
}
