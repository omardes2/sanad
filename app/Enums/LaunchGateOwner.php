<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who can actually clear a launch gate.
 *
 * This exists so the readiness screen answers "what do we do about it?" and not
 * only "is it red?". A gate owned by `Bank` or `Meta` will not move because we
 * write more code, and a gate owned by `Operations` will not move because we
 * ship another PR — mixing the three is how a launch checklist turns into a
 * list nobody acts on.
 */
enum LaunchGateOwner: string
{
    /** Our codebase. Cleared by building the thing. */
    case Sanad = 'sanad';

    /** Deployment, secrets, infrastructure. Cleared by configuring production. */
    case Operations = 'operations';

    /** Meta / WhatsApp — template approval. Outside the team. */
    case Meta = 'meta';

    /** The bank — CyberSource integration details. Outside the team. */
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Sanad => 'فريق سَنَد (تطوير)',
            self::Operations => 'التشغيل (نشر وأسرار)',
            self::Meta => 'Meta (اعتماد القالب)',
            self::Bank => 'البنك (تفاصيل CyberSource)',
        };
    }

    /** External owners cannot be unblocked from inside this repository. */
    public function isExternal(): bool
    {
        return $this === self::Meta || $this === self::Bank;
    }
}
