<?php

declare(strict_types=1);

namespace App\Data\Voice;

use App\Enums\TranscriptionFailureReason;

/**
 * What one run of the transcription pipeline did to one voice note.
 *
 * The field that carries the weight is `providerRequested`: whether a PHYSICAL,
 * paid request to the transcription provider was authorised. Every assertion
 * this phase makes about money — a refusal on the cheap side of the boundary
 * costs nothing, a persisted transcript is never re-paid for, a stale worker
 * cannot spend an attempt — is a statement about that flag, so it is a first-
 * class fact rather than something inferred from the outcome afterwards.
 *
 * `Unknown` is its own result and is never folded into `Failed`. A dispatch was
 * authorised and Sanad cannot prove the provider did or did not process (and
 * charge for) it; calling that a failure would assert something we do not know,
 * and would licence treating its cost as zero.
 */
final readonly class TranscriptionOutcome
{
    public const TRANSCRIBED = 'transcribed';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    /** Another worker holds the claim, or the row was already settled. */
    public const SKIPPED = 'skipped';

    private function __construct(
        public string $result,
        public ?TranscriptionFailureReason $reason = null,
        public bool $providerRequested = false,
        /** Short, non-sensitive note for logs and tests. Never subscriber content. */
        public ?string $note = null,
    ) {}

    public static function transcribed(): self
    {
        return new self(self::TRANSCRIBED, providerRequested: true);
    }

    public static function failed(TranscriptionFailureReason $reason, bool $providerRequested = false): self
    {
        return new self(self::FAILED, $reason, $providerRequested);
    }

    /** A paid request was authorised; the outcome is neither proven nor disproven. */
    public static function unknown(): self
    {
        return new self(self::UNKNOWN, TranscriptionFailureReason::TranscriptionUnknown, providerRequested: true);
    }

    public static function skipped(string $note): self
    {
        return new self(self::SKIPPED, note: $note);
    }

    public function succeeded(): bool
    {
        return $this->result === self::TRANSCRIBED;
    }

    /** Is the voice note's life over, whatever the reason says about proof? */
    public function settled(): bool
    {
        return $this->result === self::TRANSCRIBED || $this->result === self::FAILED;
    }
}
