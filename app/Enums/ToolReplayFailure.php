<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a REPLAY could not produce the tool's semantic result (Phase G) — a
 * closed list, and NOT a stored status: nothing here is ever written to an
 * invocation, because the invocation being replayed genuinely succeeded.
 *
 * It exists because a tool whose output is deliberately not stored in full has
 * only two honest answers on a replay: the real result, re-derived, or an
 * explicit failure. The persisted projection is the THIRD thing it must never
 * be — `{memories_count, truncated}` is audit metadata, not an answer, and
 * handing it to a model as a successful `memory.read@2` result would claim that
 * memories were returned when none were, and would disclose how many exist to a
 * caller that is no longer allowed to know.
 */
enum ToolReplayFailure: string
{
    /**
     * Consent for the capability is no longer granted. Deliberately the SAME
     * code a first-attempt refusal uses, so a revocation looks identical from
     * outside whichever attempt it happens on — and it discloses nothing: not
     * the content, not the count, not the earlier stored output.
     */
    case NotGranted = 'not_granted';

    /**
     * The read itself cannot be safely reconstructed: memory is unavailable (no
     * key), a row in the bounded set cannot be opened, or the reader could not
     * produce a result its declared schema accepts. The model is told exactly
     * that, so it can say Sanad cannot reach what it remembers right now
     * instead of answering as though there were nothing to remember.
     */
    case RehydrationUnavailable = 'memory_rehydration_unavailable';
}
