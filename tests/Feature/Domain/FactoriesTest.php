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
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates every model via its factory', function (string $model) {
    $instance = $model::factory()->create();

    expect($instance->exists)->toBeTrue()
        ->and($instance->getKey())->not->toBeNull();
})->with([
    User::class,
    ChannelAccount::class,
    Conversation::class,
    Message::class,
    Task::class,
    Reminder::class,
    Memory::class,
    Expense::class,
    WebhookEvent::class,
    UsageEvent::class,
    AuditLog::class,
]);
