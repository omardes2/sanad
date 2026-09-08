<?php

declare(strict_types=1);

namespace App\Exceptions\Tools;

use App\Enums\ToolInvocationFailureKind;
use RuntimeException;

/**
 * A domain service refused a write (Phase F3-V1): the row is not the
 * subscriber's or does not exist, or its state does not allow the change.
 *
 * It is raised INSIDE the settlement transaction, so raising it rolls back the
 * domain mutation together with the terminal transition and the invocation is
 * recorded as `failed` with a closed kind — never a half-written change.
 *
 * The message is for the log, never for the caller: `not_found` is returned
 * identically whether the row is missing or belongs to somebody else, so the
 * tool cannot be used to probe what another subscriber owns.
 */
final class ToolDomainException extends RuntimeException
{
    public function __construct(public readonly ToolInvocationFailureKind $kind, string $message)
    {
        parent::__construct($message);
    }

    /** The row does not exist, or it is not this subscriber's. The two are indistinguishable on purpose. */
    public static function notFound(string $what): self
    {
        return new self(ToolInvocationFailureKind::NotFound, "{$what} غير موجود لهذا المشترك.");
    }

    /** The row exists and is the subscriber's, but its current state does not allow this change. */
    public static function rule(string $message): self
    {
        return new self(ToolInvocationFailureKind::Rule, $message);
    }
}
