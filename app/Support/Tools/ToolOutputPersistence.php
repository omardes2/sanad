<?php

declare(strict_types=1);

namespace App\Support\Tools;

/**
 * WHAT of a tool's RESULT may be written to `tool_invocations.output`, declared
 * per tool in code (Phase G) — the mirror of `ToolInputPersistence`, and for
 * the same reason.
 *
 * A schema-validated output is not automatically a safe one to keep. The model
 * needs `memory.read@2` to return the subscriber's actual memories in order to
 * answer «شو بتعرف عني؟» — but writing those sentences onto the invocation row
 * would put a permanent, plaintext copy of the most personal data Sanad holds
 * into an operational table, in an operator's reach and in every backup, beside
 * the very rows the phase went to the trouble of encrypting.
 *
 * So the two paths are separated: the FULL output goes transiently to the model
 * for this turn, and a REDACTED projection — shape, never content — is what
 * persists. `ToolInvocationResult::output()` prefers the transient value when
 * the executor has just produced one.
 *
 * A CONSEQUENCE, stated rather than hidden: replaying a `memory.read@2`
 * invocation returns the redacted projection, because the content it would need
 * was deliberately never stored. That is correct. A replay must not be able to
 * resurrect what the policy refused to keep, and the model can simply read
 * again under a new slot.
 *
 * The default is PERSIST: most tools return ids and counts, which are exactly
 * what an audit trail is for. A tool appears here only to say less.
 */
final class ToolOutputPersistence
{
    /**
     * `name@version` ⇒ the fields whose VALUES may be stored, plus derived
     * counters computed from the ones that may not.
     *
     * @var array<string, list<string>>
     */
    private const PERSISTABLE = [
        // Never the memories themselves; only how many were returned and
        // whether the bound cut the answer short.
        'memory.read@2' => ['truncated'],
    ];

    /** Does this tool store something other than its full declared output? */
    public static function isRedacted(ToolKey $key): bool
    {
        return array_key_exists($key->value(), self::PERSISTABLE);
    }

    /**
     * The projection of one output that may be written to the row.
     *
     * @param  array<string, mixed>  $output  the schema-validated result
     * @return array<string, mixed>
     */
    public static function filter(ToolKey $key, array $output): array
    {
        if (! self::isRedacted($key)) {
            return $output;
        }

        $kept = array_intersect_key($output, array_flip(self::PERSISTABLE[$key->value()]));

        // Shape survives, content does not: a list field is replaced by its
        // length under a `<field>_count` name, so the audit trail still says
        // how much was returned.
        foreach ($output as $field => $value) {
            if (is_array($value) && ! array_key_exists($field, $kept)) {
                $kept[$field.'_count'] = count($value);
            }
        }

        ksort($kept);

        return $kept;
    }
}
