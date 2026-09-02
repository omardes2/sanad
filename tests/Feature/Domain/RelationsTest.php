<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a user to conversations and messages', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create(['user_id' => $user->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
    ]);

    expect($user->conversations)->toHaveCount(1)
        ->and($user->messages)->toHaveCount(1)
        ->and($conversation->user->is($user))->toBeTrue()
        ->and($conversation->messages->first()->is($message))->toBeTrue()
        ->and($message->conversation->is($conversation))->toBeTrue();
});

it('links a channel account to its conversations and owner', function () {
    $conversation = Conversation::factory()->create();
    $account = $conversation->channelAccount;

    expect($account->conversations->first()->is($conversation))->toBeTrue()
        ->and($account->user->is($conversation->user))->toBeTrue();
});

it('links tasks to reminders and their source message', function () {
    $user = User::factory()->create();
    $message = Message::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'source_message_id' => $message->id,
    ]);
    $reminder = Reminder::factory()->create([
        'user_id' => $user->id,
        'task_id' => $task->id,
        'source_message_id' => $message->id,
    ]);

    expect($task->reminders->first()->is($reminder))->toBeTrue()
        ->and($task->sourceMessage->is($message))->toBeTrue()
        ->and($reminder->task->is($task))->toBeTrue()
        ->and($reminder->sourceMessage->is($message))->toBeTrue()
        ->and($user->tasks)->toHaveCount(1)
        ->and($user->reminders)->toHaveCount(1);
});
