<?php

declare(strict_types=1);

namespace App\Exceptions\Tools;

use RuntimeException;

/** The consent version the caller saw is no longer current — someone granted or revoked meanwhile. Nothing written. */
final class StaleToolConsentException extends RuntimeException {}
