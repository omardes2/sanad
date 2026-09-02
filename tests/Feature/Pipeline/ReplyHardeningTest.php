<?php

declare(strict_types=1);

use App\Channels\WebSimulatorChannelAdapter;
use App\Data\OutboundMessageData;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Models\Message;
use App\Services\MessageProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A web adapter whose send() fails a configurable number of times before
 * succeeding — used to exercise delivery-retry behaviour. Bound as a shared
 * instance so its counter survives across job runs.
 */
function flakyWebAdapter(int $failTimes): WebSimulatorChannelAdapter
{
    return new class($failTimes) extends WebSimulatorChannelAdapter
    {
        public int $sendCount = 0;

        public function __construct(public int $failTimes) {}

        public function send(OutboundMessageData $message): void
        {
            $this->sendCount++;

            if ($this->sendCount <= $this->failTimes) {
                throw new RuntimeException('adapter send failed (simulated)');
            }
        }
    };
}

it('running the same inbound job repeatedly yields exactly one outbound row', function () {
    Queue::fake();
    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-1'))->message;

    pipelineRunJob($inbound->id);
    pipelineRunJob($inbound->id);
    pipelineRunJob($inbound->id);

    expect(Message::where('direction', MessageDirection::Outbound)->count())->toBe(1)
        ->and(Message::where('in_reply_to_message_id', $inbound->id)->count())->toBe(1);
});

it('the database rejects a second reply for the same inbound message', function () {
    Queue::fake();
    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-1'))->message;

    Message::create([
        'conversation_id' => $inbound->conversation_id,
        'user_id' => $inbound->user_id,
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'in_reply_to_message_id' => $inbound->id,
        'text_content' => 'first reply',
        'processing_status' => MessageProcessingStatus::Processed,
    ]);

    expect(fn () => Message::create([
        'conversation_id' => $inbound->conversation_id,
        'user_id' => $inbound->user_id,
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'in_reply_to_message_id' => $inbound->id,
        'text_content' => 'second reply',
        'processing_status' => MessageProcessingStatus::Processed,
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(Message::where('in_reply_to_message_id', $inbound->id)->count())->toBe(1);
});

it('retries a failed delivery, reuses the same reply row, and eventually sends', function () {
    Queue::fake();
    $adapter = flakyWebAdapter(failTimes: 1);
    app()->instance(WebSimulatorChannelAdapter::class, $adapter);

    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-1', 'مرحبا'))->message;

    // First attempt: reply row is created, delivery fails and re-throws.
    expect(fn () => pipelineRunJob($inbound->id))->toThrow(RuntimeException::class);

    $replyAfterFail = Message::where('in_reply_to_message_id', $inbound->id)->first();
    expect($replyAfterFail)->not->toBeNull()
        ->and($replyAfterFail->processing_status)->not->toBe(MessageProcessingStatus::Processed);

    // Second attempt (retry): same reply reused, delivery now succeeds.
    pipelineRunJob($inbound->id);

    $replies = Message::where('in_reply_to_message_id', $inbound->id)->get();
    expect($replies)->toHaveCount(1)
        ->and($replies->first()->id)->toBe($replyAfterFail->id)
        ->and($replies->first()->processing_status)->toBe(MessageProcessingStatus::Processed)
        ->and($adapter->sendCount)->toBe(2)
        ->and(Message::find($inbound->id)->processing_status)->toBe(MessageProcessingStatus::Processed);
});

it('does not mark the inbound processed when delivery fails', function () {
    Queue::fake();
    app()->instance(WebSimulatorChannelAdapter::class, flakyWebAdapter(failTimes: 5));

    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-1'))->message;

    expect(fn () => pipelineRunJob($inbound->id))->toThrow(RuntimeException::class);

    expect(Message::find($inbound->id)->processing_status)
        ->not->toBe(MessageProcessingStatus::Processed);
});
