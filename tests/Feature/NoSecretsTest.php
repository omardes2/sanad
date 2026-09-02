<?php

declare(strict_types=1);

/**
 * Guards against leaking secrets through public surfaces. We seed sentinel
 * secret values into config, then assert no public response echoes them.
 */
beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('K', 32)),
        'services.openai.api_key' => 'sk-SENTINEL-OPENAI-DO-NOT-LEAK',
        'services.whatsapp.access_token' => 'WA-SENTINEL-TOKEN-DO-NOT-LEAK',
        'services.whatsapp.app_secret' => 'WA-SENTINEL-APP-SECRET-DO-NOT-LEAK',
        'database.connections.pgsql.password' => 'SENTINEL-DB-PASSWORD-DO-NOT-LEAK',
    ]);
});

$sentinels = [
    'sk-SENTINEL-OPENAI-DO-NOT-LEAK',
    'WA-SENTINEL-TOKEN-DO-NOT-LEAK',
    'WA-SENTINEL-APP-SECRET-DO-NOT-LEAK',
    'SENTINEL-DB-PASSWORD-DO-NOT-LEAK',
];

it('does not leak secrets from the health endpoint', function () use ($sentinels) {
    $body = $this->getJson('/api/health')->getContent();

    foreach ($sentinels as $secret) {
        expect($body)->not->toContain($secret);
    }

    // Also ensure obvious secret keys are not present in the payload.
    expect(strtolower($body))
        ->not->toContain('password')
        ->not->toContain('api_key')
        ->not->toContain('access_token')
        ->not->toContain('app_secret');
});

it('does not leak secrets from the home page', function () use ($sentinels) {
    $body = $this->get('/')->getContent();

    foreach ($sentinels as $secret) {
        expect($body)->not->toContain($secret);
    }
});
