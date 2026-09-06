<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use RuntimeException;

/** The payment lifecycle moved since the caller looked (another actor won). Nothing written. */
final class StalePaymentStateException extends RuntimeException {}
