<?php

declare(strict_types=1);

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Jobs\ProcessInboundMessage;
use App\Models\Message;
use App\Services\MessageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('processing the same external id twice yields one message and a duplicate result', function () {
    Queue::fake();
    $account = pipelineWebAccount();
    $processor = app(MessageProcessor::class);

    $first = $processor->process(pipelineInbound($account, 'wamid.1'));
    $second = $processor->process(pipelineInbound($account, 'wamid.1'));

    expect($first->accepted())->toBeTrue()
        ->and($second->duplicate())->toBeTrue()
        ->and($second->message->id)->toBe($first->message->id)
        ->and(Message::where('external_message_id', 'wamid.1')->count())->toBe(1);

    Queue::assertPushed(ProcessInboundMessage::class, 1);
});

it('never generates a duplicate reply when the job is retried', function () {
    $account = pipelineWebAccount();
    $result = app(MessageProcessor::class)->process(pipelineInbound($account, 'wamid.1'));
    $id = $result->message->id;

    // Run the job three times (as if retried) — exactly one reply must exist.
    pipelineRunJob($id);
    pipelineRunJob($id);
    pipelineRunJob($id);

    $outbound = Message::where('direction', MessageDirection::Outbound)->get();

    expect($outbound)->toHaveCount(1)
        ->and($outbound->first()->in_reply_to_message_id)->toBe($id)
        ->and(Message::find($id)->processing_status)->toBe(MessageProcessingStatus::Processed);
});

it('produces exactly one inbound, one job and one reply for a repeated delivery', function () {
    Queue::fake();
    $account = pipelineWebAccount();
    $processor = app(MessageProcessor::class);

    $processor->process(pipelineInbound($account, 'wamid.42'));
    $processor->process(pipelineInbound($account, 'wamid.42')); // redelivery

    expect(Message::where('direction', MessageDirection::Inbound)->count())->toBe(1);
    Queue::assertPushed(ProcessInboundMessage::class, 1);

    $id = Message::where('external_message_id', 'wamid.42')->first()->id;
    pipelineRunJob($id);

    expect(Message::where('direction', MessageDirection::Outbound)->count())->toBe(1);
});
