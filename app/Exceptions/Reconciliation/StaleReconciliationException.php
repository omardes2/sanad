<?php

declare(strict_types=1);

namespace App\Exceptions\Reconciliation;

use RuntimeException;

/** The scope's current reconciliation (or the invoice state) moved since the caller looked. Nothing written. */
final class StaleReconciliationException extends RuntimeException {}
