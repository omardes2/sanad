<?php

declare(strict_types=1);

use App\Logging\RedactSecrets;
use Illuminate\Support\Facades\Log;

/**
 * The log channels tapped with App\Logging\RedactSecrets never write a secret
 * from a context array, even if a developer passes one by mistake.
 */
it('redacts secrets from log context before the handler writes them', function () {
    $path = storage_path('logs/redaction-test.log');
    @unlink($path);

    config(['logging.channels.redaction_test' => [
        'driver' => 'single',
        'path' => $path,
        'level' => 'debug',
        'tap' => [RedactSecrets::class],
    ]]);

    Log::channel('redaction_test')->info('provider call', [
        'provider' => 'openai',
        'api_key' => 'sk-live-should-never-be-written',
        'headers' => ['Authorization' => 'Bearer abcdefghijklmnopqrstuvwxyz'],
        'message_id' => 42,
    ]);

    $written = (string) file_get_contents($path);
    @unlink($path);

    expect($written)->toContain('provider call')
        ->and($written)->toContain('"provider":"openai"')
        ->and($written)->toContain('"message_id":42')
        ->and($written)->not->toContain('sk-live-should-never-be-written')
        ->and($written)->not->toContain('abcdefghijklmnopqrstuvwxyz')
        ->and($written)->toContain('[REDACTED:');
});
