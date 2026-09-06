<?php

declare(strict_types=1);

namespace App\Exceptions\Close;

use RuntimeException;

/** The close scope moved since the caller looked (another close / reopen won). Nothing written. */
final class StaleCloseException extends RuntimeException {}
