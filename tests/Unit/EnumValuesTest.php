<?php

declare(strict_types=1);

use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Enums\ReminderStatus;
use App\Enums\ReplyMode;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Enums\WebhookEventStatus;

it('backs every domain enum with the expected string values', function () {
    expect(array_map(fn ($c) => $c->value, UserStatus::cases()))
        ->toBe(['pending', 'active', 'suspended']);

    expect(array_map(fn ($c) => $c->value, ReplyMode::cases()))
        ->toBe(['text', 'voice', 'auto']);

    expect(array_map(fn ($c) => $c->value, ChannelType::cases()))
        ->toBe(['whatsapp', 'web']);

    expect(array_map(fn ($c) => $c->value, ChannelAccountStatus::cases()))
        ->toBe(['active', 'disconnected', 'blocked']);

    expect(array_map(fn ($c) => $c->value, ConversationStatus::cases()))
        ->toBe(['active', 'closed', 'archived']);

    expect(array_map(fn ($c) => $c->value, MessageDirection::cases()))
        ->toBe(['inbound', 'outbound']);

    expect(array_map(fn ($c) => $c->value, MessageType::cases()))
        ->toBe(['text', 'audio', 'image', 'document', 'location', 'interactive', 'system']);

    expect(array_map(fn ($c) => $c->value, MessageProcessingStatus::cases()))
        ->toBe(['received', 'queued', 'processing', 'processed', 'failed']);

    expect(array_map(fn ($c) => $c->value, TaskStatus::cases()))
        ->toBe(['pending', 'in_progress', 'completed', 'cancelled']);

    expect(array_map(fn ($c) => $c->value, TaskPriority::cases()))
        ->toBe(['low', 'normal', 'high', 'urgent']);

    expect(array_map(fn ($c) => $c->value, ReminderStatus::cases()))
        ->toBe(['pending', 'processing', 'sent', 'failed', 'cancelled']);

    expect(array_map(fn ($c) => $c->value, WebhookEventStatus::cases()))
        ->toBe(['received', 'processing', 'processed', 'failed']);
});

it('resolves enum instances from their backing value', function () {
    expect(TaskStatus::from('in_progress'))->toBe(TaskStatus::InProgress)
        ->and(UserStatus::tryFrom('active'))->toBe(UserStatus::Active)
        ->and(UserStatus::tryFrom('nope'))->toBeNull();
});
