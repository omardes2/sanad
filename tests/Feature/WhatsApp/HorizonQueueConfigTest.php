<?php

declare(strict_types=1);

it('configures Horizon to consume the webhooks and messages queues automatically', function () {
    $queues = config('horizon.defaults.supervisor-1.queue');

    // Regression guard for the production incident where the webhooks queue was
    // never drained by Horizon and had to be worked manually.
    expect($queues)->toBeArray()
        ->toContain('webhooks')
        ->toContain('messages')
        ->toContain('default');
});

it('keeps the Horizon worker timeout below the redis retry_after to avoid double runs', function () {
    $timeout = (int) config('horizon.defaults.supervisor-1.timeout');
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($timeout)->toBeLessThan($retryAfter);
});
