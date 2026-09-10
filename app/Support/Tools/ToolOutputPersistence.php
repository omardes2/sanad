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
 * REPLAY. A redacted read would otherwise lose its answer to an infrastructure
 * retry: the queue re-processes the same message, the same slot replays, and the
 * projection is all that is left. So such a read is declared REHYDRATABLE — on a
 * proven replay its result is re-derived from the live data rather than read
 * back from the row (see `rehydratableOnReplay()`). Nothing is stored either
 * way, and no second invocation, event, audit or usage row exists.
 *
 * AND WHEN IT CANNOT BE RE-DERIVED, THE PROJECTION IS STILL NOT THE ANSWER. What
 * is kept here is audit metadata; returning it as a successful result would claim
 * the tool produced something it did not, and would disclose the shape of data to
 * a caller that may no longer be allowed to see it. The replay reports a bounded
 * failure instead (`ToolReplayFailure`).
 *
 * The default is PERSIST: most tools return ids and counts, which are exactly
 * what an audit trail is for. A tool appears here only to say less.
 */
final class ToolOutputPersistence
{
    /**
     * `name@version` ⇒ the fields whose VALUES may be stored, plus derived
     * counters computed from the ones that may not.
     */
    /**
     * `name@version` of the redacted READS whose result may be re-derived on a
     * replay. Every entry must also appear in `PERSISTABLE` and must be a
     * `read`; a contract test pins both.
     *
     * @var list<string>
     */
    private const REHYDRATABLE = ['memory.read@2'];

    /**
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
     * May a REPLAY of this tool re-derive the part of its result that was never
     * stored, instead of handing back the projection?
     *
     * This exists because redaction and infrastructure retries would otherwise
     * contradict each other. A queue retry re-processes the same inbound message
     * at the same invocation slot: the read replays, and with only the shape on
     * the row the model can no longer answer the question it asked in the first
     * place. Losing the answer to a retry is not a privacy win — it is a broken
     * reply — and persisting the plaintext to avoid it is exactly what the
     * policy refuses.
     *
     * So a redacted READ may be re-derived on replay. The executor proves the
     * slot, the tool version, the canonical input hash, the read side-effect
     * class and live consent before it does, and the re-derivation creates no
     * invocation, no event, no audit and no usage row: it is REPLAY OUTPUT
     * REHYDRATION, not a second execution.
     *
     * It is declared per tool rather than inferred, and only a `read` may ever
     * qualify — re-deriving a write would BE a second write.
     */
    public static function rehydratableOnReplay(ToolKey $key): bool
    {
        return in_array($key->value(), self::REHYDRATABLE, true);
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
