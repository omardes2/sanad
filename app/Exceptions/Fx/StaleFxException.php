<?php

declare(strict_types=1);

namespace App\Exceptions\Fx;

use RuntimeException;

/** The rate revision / conversion revision the caller saw is no longer current (or the chosen rate was superseded). Nothing written. */
final class StaleFxException extends RuntimeException {}
