<?php

declare(strict_types=1);

it('exposes a health endpoint with the expected shape', function () {
    $response = $this->getJson('/api/health');

    // 200 when all dependencies are up, 503 when a dependency is down.
    expect($response->status())->toBeIn([200, 503]);

    $response->assertJsonStructure([
        'status',
        'app',
        'environment',
        'services' => ['postgres', 'redis'],
        'timestamp',
    ]);
});

it('reports the application name as SANAD', function () {
    $this->getJson('/api/health')
        ->assertJsonPath('app', 'SANAD');
});

it('reports the testing environment', function () {
    $this->getJson('/api/health')
        ->assertJsonPath('environment', 'testing');
});

it('reports the database as ok on the in-memory connection', function () {
    // The test suite runs on sqlite :memory:, so the DB probe must succeed.
    $this->getJson('/api/health')
        ->assertJsonPath('services.postgres', 'ok');
});
