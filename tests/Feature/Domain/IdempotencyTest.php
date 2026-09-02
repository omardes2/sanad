<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prevents processing the same external message id twice', function () {
    Message::factory()->create(['external_message_id' => 'wamid.DUPLICATE']);

    expect(fn () => Message::factory()->create(['external_message_id' => 'wamid.DUPLICATE']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows multiple messages with a null external message id', function () {
    Message::factory()->outbound()->create(['external_message_id' => null]);
    Message::factory()->outbound()->create(['external_message_id' => null]);

    expect(Message::whereNull('external_message_id')->count())->toBe(2);
});

it('prevents duplicate webhook events for the same provider and external id', function () {
    WebhookEvent::factory()->create([
        'provider' => 'whatsapp',
        'external_event_id' => 'evt-123',
    ]);

    expect(fn () => WebhookEvent::factory()->create([
        'provider' => 'whatsapp',
        'external_event_id' => 'evt-123',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same external id across different providers', function () {
    WebhookEvent::factory()->create(['provider' => 'whatsapp', 'external_event_id' => 'shared-id']);
    WebhookEvent::factory()->create(['provider' => 'web', 'external_event_id' => 'shared-id']);

    expect(WebhookEvent::where('external_event_id', 'shared-id')->count())->toBe(2);
});
