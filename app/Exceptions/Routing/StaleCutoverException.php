<?php

declare(strict_types=1);

namespace App\Exceptions\Routing;

use RuntimeException;

/**
 * The state the admin previewed changed before the apply ran (another admin
 * won the race). Nothing is written; the admin must preview again.
 */
final class StaleCutoverException extends RuntimeException {}
