<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Expense;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('cascades deletion of a user to their personal data', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create(['user_id' => $user->id]);
    Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $user->id]);
    Task::factory()->create(['user_id' => $user->id]);
    Reminder::factory()->create(['user_id' => $user->id]);
    Memory::factory()->create(['user_id' => $user->id]);
    Expense::factory()->create(['user_id' => $user->id]);

    $user->delete();

    expect(ChannelAccount::count())->toBe(0)
        ->and(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(Task::count())->toBe(0)
        ->and(Reminder::count())->toBe(0)
        ->and(Memory::count())->toBe(0)
        ->and(Expense::count())->toBe(0);
});

it('retains audit logs and usage events but nulls the user on deletion', function () {
    $user = User::factory()->create();
    $audit = AuditLog::factory()->create(['user_id' => $user->id]);
    $usage = UsageEvent::factory()->create(['user_id' => $user->id]);

    $user->delete();

    expect(AuditLog::count())->toBe(1)
        ->and(UsageEvent::count())->toBe(1)
        ->and($audit->fresh()->user_id)->toBeNull()
        ->and($usage->fresh()->user_id)->toBeNull();
});

it('nulls source_message_id on a task when its source message is deleted', function () {
    $user = User::factory()->create();
    $message = Message::factory()->create(['user_id' => $user->id]);
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'source_message_id' => $message->id,
    ]);

    $message->delete();

    expect($task->fresh())->not->toBeNull()
        ->and($task->fresh()->source_message_id)->toBeNull();
});
