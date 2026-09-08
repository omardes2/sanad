<?php

declare(strict_types=1);

namespace App\Support\Tools;

/**
 * WHICH tool arguments may be stored, declared per field in code (Phase F2).
 *
 * Passing a closed schema does not make a value safe. `memory.read@1.query` is
 * bounded, control-character free and 200 characters at most — and it is still
 * subscriber-authored text that may legally contain a name, a phone number, an
 * email address or a private sentence. Storing it because it validated would
 * put that content in an operational table, an operator's reach and every
 * backup, forever.
 *
 * F2 never re-executes a read after a crash, so it needs no raw input to
 * recover: the canonical value exists in memory for the duration of the call,
 * the deterministic `input_hash` is what decides replay versus conflict, and
 * the invocation stores the hash plus the FIELD NAMES that were present.
 *
 * This map is the explicit exception list — a field must be named here to have
 * its value persisted, review is a code review, and the default for anything
 * unlisted is SENSITIVE. Every tool shipped so far persists nothing.
 *
 * There is no regex, no heuristic and no post-hoc redaction anywhere: a value
 * that is not persistable simply never reaches the insert.
 */
final class ToolInputPersistence
{
    /**
     * `name@version` ⇒ the fields whose VALUES may be stored on the invocation.
     * Everything else, and every tool absent from this map, is sensitive.
     *
     * @var array<string, list<string>>
     */
    private const PERSISTABLE = [
        // `query` is the subscriber's own words; `limit` is theirs to ask for
        // and tells an operator nothing the output does not already say.
        'memory.read@1' => [],
        'task.create@1' => [],
        'task.list@1' => [],
        'task.complete@1' => [],
        'reminder.create@1' => [],
        'reminder.create@2' => [],
        'reminder.cancel@1' => [],
    ];

    /** @return list<string> */
    public static function persistable(ToolKey $key): array
    {
        return self::PERSISTABLE[$key->value()] ?? [];
    }

    /** Fails closed: anything not explicitly allowed is sensitive. */
    public static function isSensitive(ToolKey $key, string $field): bool
    {
        return ! in_array($field, self::persistable($key), true);
    }

    /**
     * The subset of a canonical input that may be written to the row.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function filter(ToolKey $key, array $values): array
    {
        return array_intersect_key($values, array_flip(self::persistable($key)));
    }

    /**
     * Safe structural metadata: WHICH declared fields the call carried, never
     * what they said.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function fields(array $values): array
    {
        $names = array_keys($values);
        sort($names);

        return array_values($names);
    }
}
