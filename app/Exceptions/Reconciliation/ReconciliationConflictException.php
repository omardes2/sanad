<?php

declare(strict_types=1);

namespace App\Exceptions\Reconciliation;

use RuntimeException;

/** The idempotency key / invoice reference already belongs to a record with DIFFERENT facts. Nothing written. */
final class ReconciliationConflictException extends RuntimeException {}
