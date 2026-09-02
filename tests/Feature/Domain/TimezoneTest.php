<?php

declare(strict_types=1);

use App\Models\Reminder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs the application in UTC internally', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(now()->timezone->getName())->toBe('UTC');
});

it('stores and reads datetimes in UTC', function () {
    $remindAt = now()->startOfSecond();
    $reminder = Reminder::factory()->create(['remind_at' => $remindAt]);

    $fresh = $reminder->fresh();

    expect($fresh->remind_at->timezone->getName())->toBe('UTC')
        // Compare at second precision (storage may drop sub-second fractions).
        ->and($fresh->remind_at->format('Y-m-d H:i:s'))->toBe($remindAt->format('Y-m-d H:i:s'));
});

it('interprets a stored naive datetime as UTC when read back', function () {
    // Insert a raw UTC timestamp, then read it through the model cast.
    $reminder = Reminder::factory()->create([
        'remind_at' => '2026-01-15 09:30:00',
    ]);

    $fresh = $reminder->fresh();

    expect($fresh->remind_at->timezone->getName())->toBe('UTC')
        ->and($fresh->remind_at->format('Y-m-d H:i:s'))->toBe('2026-01-15 09:30:00');
});
