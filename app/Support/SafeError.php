<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Produces a privacy-safe one-line summary of an exception for storage/logs.
 *
 * Only exceptions WE author (App\Exceptions\*) are guaranteed by construction
 * to carry no secrets or personal data, so only their message is echoed. For
 * any other exception — framework/DB/PDO/third-party — we store the class name
 * only, because their messages can embed SQL bindings (message text, phone
 * numbers) or other sensitive context.
 */
final class SafeError
{
    public static function summarize(?Throwable $e): string
    {
        if ($e === null) {
            return 'unknown error';
        }

        $class = class_basename($e);

        if (str_starts_with($e::class, 'App\\Exceptions\\')) {
            return $class.': '.mb_substr($e->getMessage(), 0, 300);
        }

        return $class;
    }
}
