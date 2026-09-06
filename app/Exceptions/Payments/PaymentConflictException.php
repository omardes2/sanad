<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * The idempotency key or external reference already belongs to a record with
 * DIFFERENT facts. Nothing was written; the caller must not retry blindly.
 */
final class PaymentConflictException extends RuntimeException {}
