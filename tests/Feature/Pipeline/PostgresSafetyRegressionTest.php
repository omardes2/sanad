<?php

declare(strict_types=1);

use App\Contracts\AgentOrchestrator;
use App\Data\AgentResponseData;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Jobs\ProcessInboundMessage;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\MessageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Regressions for three defects that only PostgreSQL exposes (SQLite masks
 * them). Every test here runs inside RefreshDatabase's transaction, which is
 * exactly the condition under which PostgreSQL aborts the transaction after a
 * failed statement — so each one is green only when the code is pgsql-safe.
 *
 *  1. failed() updated `metadata->last_error` via a JSON path (jsonb_set), which
 *     PostgreSQL rejects when the stored metadata is a JSON array ("[]").
 *  2. resolveReply() did create() → catch unique violation → re-query; the
 *     violation aborts an open PostgreSQL transaction, so the re-query failed.
 *  3. The WhatsApp webhook stored the envelope with the same fragile pattern.
 */

// ---- 1) failed(): read-merge-write the whole metadata document -------------

function failInbound(Message $inbound): Message
{
    (new ProcessInboundMessage($inbound->id))->failed(new RuntimeException('provider exploded'));

    return $inbound->fresh();
}

it('failed() does not break when metadata is empty (stored as a JSON array)', function () {
    Queue::fake();
    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'pg-empty'))->message;
    $inbound->forceFill(['metadata' => []])->save(); // "[]" — the shape jsonb_set rejects

    $failed = failInbound($inbound);

    expect($failed->processing_status)->toBe(MessageProcessingStatus::Failed)
        ->and($failed->metadata['last_error'])->toContain('RuntimeException');
});

it('failed() does not break when metadata is null', function () {
    Queue::fake();
    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'pg-null'))->message;
    $inbound->forceFill(['metadata' => null])->save();

    $failed = failInbound($inbound);

    expect($failed->processing_status)->toBe(MessageProcessingStatus::Failed)
        ->and($failed->metadata)->toBe(['last_error' => $failed->metadata['last_error']]);
});

it('failed() preserves existing metadata keys and adds last_error', function () {
    Queue::fake();
    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'pg-keep'))->message;
    $inbound->forceFill(['metadata' => ['provider' => 'whatsapp', 'profile_name' => 'عمر', 'wa_timestamp' => '1725500000']])->save();

    $failed = failInbound($inbound);

    expect($failed->metadata['provider'])->toBe('whatsapp')
        ->and($failed->metadata['profile_name'])->toBe('عمر')
        ->and($failed->metadata['wa_timestamp'])->toBe('1725500000')
        ->and($failed->metadata['last_error'])->toContain('RuntimeException')
        // The document is still a JSON object with exactly the merged keys.
        ->and(array_keys($failed->metadata))->toBe(['provider', 'profile_name', 'wa_timestamp', 'last_error']);
});

// ---- 2) resolveReply(): a concurrent creator wins the unique constraint ----

it('resolveReply reuses the reply a concurrent worker inserted, without a second row or an aborted transaction', function () {
    Queue::fake();
    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'pg-race'))->message;

    // Simulate the race: the reply row appears AFTER the job's initial lookup
    // (which found nothing) and BEFORE its insert — i.e. while the agent runs.
    app()->instance(AgentOrchestrator::class, new class implements AgentOrchestrator
    {
        public function handle(User $user, Conversation $conversation, Message $message): AgentResponseData
        {
            Message::create([
                'conversation_id' => $message->conversation_id,
                'user_id' => $message->user_id,
                'direction' => MessageDirection::Outbound,
                'type' => MessageType::Text,
                'in_reply_to_message_id' => $message->id,
                'text_content' => 'الرد الذي أنشأه العامل المتزامن',
                'processing_status' => MessageProcessingStatus::Queued,
            ]);

            return new AgentResponseData(text: 'ردّ العامل الحالي (يجب ألّا يُخزَّن)');
        }
    });

    pipelineRunJob($inbound->id);

    $replies = Message::where('in_reply_to_message_id', $inbound->id)->get();

    // Exactly one reply, and it is the concurrent worker's; the job reused it.
    expect($replies)->toHaveCount(1)
        ->and($replies->first()->text_content)->toBe('الرد الذي أنشأه العامل المتزامن')
        // The transaction survived the unique violation: processing completed.
        ->and($replies->first()->fresh()->processing_status)->toBe(MessageProcessingStatus::Processed)
        ->and($inbound->fresh()->processing_status)->toBe(MessageProcessingStatus::Processed);
});

// ---- 3) Webhook: duplicate redelivery ----------------------------------------

it('a duplicate webhook redelivery is acknowledged, stored once, dispatched once, and leaves the transaction usable', function () {
    Queue::fake();
    whatsappConfigure();
    $envelope = whatsappTextEnvelope('wamid.pg-dup', '970599000001', 'مرحبا');

    postWhatsAppEnvelope($envelope)->assertOk();
    postWhatsAppEnvelope($envelope)->assertOk(); // exact same bytes → unique (provider, external_event_id)

    // Any further statement on this connection proves it was not aborted.
    expect(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::first()->external_event_id)->toBe(hash('sha256', json_encode($envelope, JSON_UNESCAPED_UNICODE)));
    Queue::assertPushed(ProcessWhatsAppWebhook::class, 1);
});
