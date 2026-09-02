<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\ReminderStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts user enum attributes to backed enum instances', function () {
    $user = User::factory()->create(['status' => UserStatus::Active]);

    expect($user->fresh()->status)->toBeInstanceOf(UserStatus::class)
        ->and($user->fresh()->status)->toBe(UserStatus::Active);
});

it('casts message direction and type to enums', function () {
    $message = Message::factory()->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Image,
    ]);
    $fresh = $message->fresh();

    expect($fresh->direction)->toBe(MessageDirection::Inbound)
        ->and($fresh->type)->toBe(MessageType::Image);
});

it('casts task and reminder enums', function () {
    $task = Task::factory()->create([
        'status' => TaskStatus::InProgress,
        'priority' => TaskPriority::High,
    ]);
    $reminder = Reminder::factory()->create([
        'channel' => ChannelType::WhatsApp,
        'status' => ReminderStatus::Pending,
    ]);

    expect($task->fresh()->status)->toBe(TaskStatus::InProgress)
        ->and($task->fresh()->priority)->toBe(TaskPriority::High)
        ->and($reminder->fresh()->channel)->toBe(ChannelType::WhatsApp)
        ->and($reminder->fresh()->status)->toBe(ReminderStatus::Pending);
});

it('persists enum values as their string backing value', function () {
    $user = User::factory()->create(['status' => UserStatus::Suspended]);

    $raw = DB::table('users')->where('id', $user->id)->value('status');

    expect($raw)->toBe('suspended');
});
