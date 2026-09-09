<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Models\Message;
use App\Support\Memory\ExplicitMemoryIntent;

/**
 * WHICH tools need a server-verifiable EXPLICIT INSTRUCTION on the inbound
 * message, over and above consent (Phase G).
 *
 * Consent answers "may Sanad ever do this for this subscriber". It does not
 * answer "did they ask for it THIS time" — and for durable memory those are
 * different questions. A model that judges «أنا بحب القهوة سادة» worth keeping
 * has made a suggestion; only «احفظ إني بحب القهوة سادة» is authority. So a
 * tool listed here executes only when the SERVER can find that instruction in
 * the subscriber's own stored words.
 *
 * The map is OPT-IN, not fail-closed, and that is a deliberate difference from
 * every other map in this layer: most tools take their authority from consent
 * plus the subscriber's request being the reason the turn exists at all, and
 * demanding a magic phrase before «ذكرني بكرا» would break them. Only where a
 * write persists personal data BEYOND the conversation does a per-message
 * instruction become the right bar — which today is exactly one tool.
 *
 * The verifier is resolved from code, keyed by tool key. A definition still
 * cannot name it, and no row or payload can.
 */
final class ToolIntentRequirements
{
    /** @var array<string, callable(Message): bool> */
    private const VERIFIERS = [
        'memory.write@1' => [ExplicitMemoryIntent::class, 'present'],
    ];

    public static function required(ToolKey $key): bool
    {
        return array_key_exists($key->value(), self::VERIFIERS);
    }

    /**
     * Is the requirement satisfied for this message? A tool with no declared
     * requirement is satisfied trivially — it never had one.
     */
    public static function satisfied(ToolKey $key, Message $message): bool
    {
        $verifier = self::VERIFIERS[$key->value()] ?? null;

        return $verifier === null || (bool) $verifier($message);
    }

    /** @return list<string> the tools that demand an explicit instruction */
    public static function keys(): array
    {
        return array_keys(self::VERIFIERS);
    }
}
