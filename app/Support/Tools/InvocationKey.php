<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolRuleException;

/**
 * The SERVER-GENERATED identity of one tool invocation (Phase F2, decision D2).
 *
 *     msg:<message_id>:tool:<name>@<version>:call:<n>
 *
 * Every part is a persisted fact: the id of the stored message being answered,
 * the tool key and version resolved from the code registry, and the 1-based
 * position of this call in that message's deterministic plan. There is no
 * random component, no clock and no caller-supplied text, so re-processing the
 * same stored message with the same ordered plan derives BYTE-IDENTICAL keys.
 *
 * The model, the provider and the tool input can influence none of it. A
 * replacement key is never minted: if the call at position `n` is not the call
 * that was executed there before, the identity collides on purpose and the
 * input hash reports a CONFLICT rather than quietly running something new.
 *
 * The format is internal. Nothing outside the platform may send one in, and it
 * is never used as a foreign-domain identity (the ledger links to the
 * invocation's own id).
 */
final readonly class InvocationKey
{
    public const MAX = 191;

    /** A message id, and a call index inside that message. */
    private const MAX_CALL_INDEX = 999;

    private function __construct(public string $value) {}

    /**
     * @throws ToolRuleException
     */
    public static function of(int $messageId, ToolKey $tool, int $callIndex): self
    {
        if ($messageId < 1) {
            throw ToolRuleException::of('message', 'الاستدعاء يشتقّ هويته من رسالة مخزَّنة؛ معرّف الرسالة يجب أن يكون موجبًا.');
        }

        if ($callIndex < 1 || $callIndex > self::MAX_CALL_INDEX) {
            throw ToolRuleException::of('call_index', 'ترتيب النداء داخل الرسالة يجب أن يكون بين 1 و'.self::MAX_CALL_INDEX.'.');
        }

        $value = 'msg:'.$messageId.':tool:'.$tool->value().':call:'.$callIndex;

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
