<?php

declare(strict_types=1);

use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('Reminder::due() returns only pending reminders whose time has passed', function () {
    $due = Reminder::factory()->due()->create();
    Reminder::factory()->create(['remind_at' => now()->addHour(), 'status' => ReminderStatus::Pending]); // future
    Reminder::factory()->sent()->create(['remind_at' => now()->subHour()]); // already sent

    $result = Reminder::due()->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->is($due))->toBeTrue();
});

it('Task::incomplete() returns only pending and in-progress tasks', function () {
    $pending = Task::factory()->create(['status' => TaskStatus::Pending]);
    $inProgress = Task::factory()->create(['status' => TaskStatus::InProgress]);
    Task::factory()->create(['status' => TaskStatus::Completed]);
    Task::factory()->create(['status' => TaskStatus::Cancelled]);

    $ids = Task::incomplete()->pluck('id');

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($pending->id, $inProgress->id);
});

it('Message::inbound() returns only inbound messages', function () {
    $inbound = Message::factory()->inbound()->create();
    Message::factory()->outbound()->create();

    $result = Message::inbound()->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->is($inbound))->toBeTrue();
});

it('WebhookEvent::pending() returns only received (unprocessed) events', function () {
    $pending = WebhookEvent::factory()->create();
    WebhookEvent::factory()->processed()->create();

    $result = WebhookEvent::pending()->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->is($pending))->toBeTrue();
});
