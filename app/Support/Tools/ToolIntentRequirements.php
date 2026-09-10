<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Models\Message;
use App\Support\FollowUps\ExplicitFollowUpIntent;
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
 * demanding a magic phrase before «ذكرني بكرا» would break them. The bar belongs
 * where a write reaches BEYOND the conversation — creating durable personal data
 * or destroying it — which today is the two memory writes.
 *
 * The verifier is resolved from code, keyed by tool key. A definition still
 * cannot name it, and no row or payload can.
 */
final class ToolIntentRequirements
{
    /** @var array<string, callable(Message): bool> */
    private const VERIFIERS = [
        'memory.write@1' => [ExplicitMemoryIntent::class, 'present'],
        // Forgetting destroys what the subscriber deliberately kept, so it needs
        // the SAME bar, not a lower one: a model that reads a contradiction
        // («بطلت أحب القهوة») as permission would archive a fact nobody asked it
        // to touch.
        'memory.forget@1' => [ExplicitMemoryIntent::class, 'forgetPresent'],
        /*
         * A follow-up is a LICENCE TO SPEAK LATER, unprompted and more than once.
         * The model proposing one is a suggestion, not authority: «بكرا بدفع
         * الفاتورة» must not become days of messages because the model judged it
         * helpful. Ending a loop is deliberately NOT gated — stopping unsolicited
         * messages is the safe direction, and a subscriber who phrases it oddly
         * must still be able to stop them.
         */
        'follow_up.create@1' => [ExplicitFollowUpIntent::class, 'present'],
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
