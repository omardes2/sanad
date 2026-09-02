<?php

declare(strict_types=1);

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Jobs\ProcessInboundMessage;
use App\Models\Message;
use App\Services\MessageProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('is configured for the messages queue with retries and backoff', function () {
    $job = new ProcessInboundMessage(1);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe('messages')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 15, 30])
        ->and($job->uniqueId())->toBe('process-inbound-message:1');
});

it('processes an inbound message: transitions status and saves an outbound reply', function () {
    Queue::fake(); // isolate: capture dispatch, run job manually
    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-1', 'مرحبا'))->message;

    expect($inbound->processing_status)->toBe(MessageProcessingStatus::Queued);

    pipelineRunJob($inbound->id);

    $inbound->refresh();
    $outbound = Message::where('direction', MessageDirection::Outbound)->first();

    expect($inbound->processing_status)->toBe(MessageProcessingStatus::Processed)
        ->and($inbound->processed_at)->not->toBeNull()
        ->and($outbound)->not->toBeNull()
        ->and($outbound->text_content)->toBe('أهلًا! أنا سَنَد، مساعدك الشخصي الذكي. كيف بقدر أساعدك؟')
        ->and($outbound->processing_status)->toBe(MessageProcessingStatus::Processed);
});

it('marks the message failed with a safe error via failed()', function () {
    Queue::fake();
    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-1'))->message;

    (new ProcessInboundMessage($inbound->id))->failed(new RuntimeException('db exploded: secret-conn-string'));

    $inbound->refresh();
    expect($inbound->processing_status)->toBe(MessageProcessingStatus::Failed)
        ->and($inbound->metadata['last_error'] ?? '')->toContain('RuntimeException');
});

it('does nothing for a non-existent message id', function () {
    Queue::fake();

    pipelineRunJob(999999);

    expect(Message::count())->toBe(0);
});
