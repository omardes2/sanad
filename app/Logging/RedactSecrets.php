<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\Security\SecretRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Log channel "tap": redacts secrets from every record's context and extra
 * before any handler writes it, using the same SecretRedactor as the audit
 * log. The convention stays "never put a secret in a log call"; this is the
 * safety net for the day someone does.
 */
final class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $redactor = app(SecretRedactor::class);

        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(static function (LogRecord $record) use ($redactor): LogRecord {
                return $record->with(
                    context: $redactor->redact($record->context),
                    extra: $redactor->redact($record->extra),
                );
            });
        }
    }
}
