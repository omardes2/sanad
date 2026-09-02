<?php

declare(strict_types=1);

use App\Data\InboundMessageData;
use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Jobs\ProcessInboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MessageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('accepts a valid inbound message: saves it and queues the job', function () {
    Queue::fake();
    $account = pipelineWebAccount();

    $result = app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-1'));

    expect($result->accepted())->toBeTrue()
        ->and($result->message)->not->toBeNull();

    $message = $result->message;
    expect($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->processing_status)->toBe(MessageProcessingStatus::Queued)
        ->and($message->text_content)->toBe('مرحبا');

    Queue::assertPushed(
        ProcessInboundMessage::class,
        fn (ProcessInboundMessage $job) => $job->messageId === $message->id && $job->queue === 'messages',
    );
});

it('creates a conversation when none exists yet', function () {
    Queue::fake();
    $account = pipelineWebAccount();

    expect(Conversation::count())->toBe(0);

    app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-1'));

    expect(Conversation::count())->toBe(1)
        ->and(Conversation::first()->channel_account_id)->toBe($account->id);
});

it('reuses the active conversation for subsequent messages', function () {
    Queue::fake();
    $account = pipelineWebAccount();
    $processor = app(MessageProcessor::class);

    $processor->process(pipelineInbound($account, 'ext-1'));
    $processor->process(pipelineInbound($account, 'ext-2'));

    expect(Conversation::count())->toBe(1)
        ->and(Message::where('direction', MessageDirection::Inbound)->count())->toBe(2);
});

it('rejects a message from an unknown channel account', function () {
    Queue::fake();

    $result = app(MessageProcessor::class)->process(new InboundMessageData(
        channel: ChannelType::Web,
        externalMessageId: 'ext-1',
        externalUserId: 'nobody',
        type: MessageType::Text,
        text: 'مرحبا',
    ));

    expect($result->rejected())->toBeTrue()
        ->and(Message::count())->toBe(0);

    Queue::assertNothingPushed();
});

it('rejects a message with a blank external message id', function () {
    Queue::fake();
    $account = pipelineWebAccount();

    $result = app(MessageProcessor::class)->process(pipelineInbound($account, '   '));

    expect($result->rejected())->toBeTrue()
        ->and(Message::count())->toBe(0);
    Queue::assertNothingPushed();
});
