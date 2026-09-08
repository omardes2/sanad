<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolRuleException;

/**
 * The SERVER-OWNED CALL SLOT that identifies one invocation (Phase F2, D2).
 *
 *     msg:<message_id>:call:<n>
 *
 * The identity is the SLOT, deliberately not the tool. Which tool the model
 * proposed for a slot is a claim FACT stored on the invocation, not part of its
 * name — if the tool were in the key, proposing `task.create@1` where
 * `memory.read@1` ran would mint a second identity and a second invocation for
 * the same logical call, which is exactly what must never happen. With the slot
 * as the identity, a different tool, a different version or a different input
 * at an existing slot is a CONFLICT against the stored facts, and there is
 * never a second row.
 *
 * Both parts are persisted facts: the id of the stored message being answered,
 * and the 1-based position of this call in that message's deterministic plan.
 * No clock, no randomness, no caller text — so re-planning the same message
 * derives byte-identical keys. `ToolCallPlan` is the only production authority
 * that assigns the index, and nothing a model, a provider or a tool input says
 * can influence either part. A replacement key is never minted.
 *
 * The format is internal: nothing outside the platform may send one in, and it
 * is never used as a foreign-domain identity (the ledger links to the
 * invocation's own numeric id).
 */
final readonly class InvocationKey
{
    public const MAX = 191;

    /** A message holds at most this many intended tool calls. */
    public const MAX_CALL_INDEX = 999;

    private function __construct(public string $value) {}

    /**
     * @throws ToolRuleException
     */
    public static function of(int $messageId, int $callIndex): self
    {
        if ($messageId < 1) {
            throw ToolRuleException::of('message', 'الاستدعاء يشتقّ هويته من رسالة مخزَّنة؛ معرّف الرسالة يجب أن يكون موجبًا.');
        }

        if ($callIndex < 1 || $callIndex > self::MAX_CALL_INDEX) {
            throw ToolRuleException::of('call_index', 'ترتيب النداء داخل الرسالة يجب أن يكون بين 1 و'.self::MAX_CALL_INDEX.'.');
        }

        $value = 'msg:'.$messageId.':call:'.$callIndex;

        if (strlen($value) > self::MAX) {
            throw ToolRuleException::of('idempotency_key', 'هوية الاستدعاء تجاوزت الحدّ المسموح.');
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
