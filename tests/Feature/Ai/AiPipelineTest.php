<?php

declare(strict_types=1);

use App\Enums\MessageDirection;
use App\Models\Message;
use App\Services\MessageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeGroq(string $content, int $status = 200): void
{
    Http::fake(['api.groq.com/*' => Http::response([
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
    ], $status)]);
}

it('delivers the AI reply end-to-end when AI is enabled', function () {
    aiConfigure();
    fakeGroq('رد الذكاء الاصطناعي');

    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-ai-1', 'مرحبا'))->message;
    pipelineRunJob($inbound->id);

    $reply = Message::where('direction', MessageDirection::Outbound)->first();

    expect($reply)->not->toBeNull()
        ->and($reply->text_content)->toBe('رد الذكاء الاصطناعي')
        ->and($reply->metadata['ai']['provider'])->toBe('groq');
});

it('keeps using the deterministic placeholder when AI is disabled', function () {
    config(['ai.enabled' => false]);
    Http::fake(); // record outbound HTTP so we can assert none is sent

    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-ai-2', 'مرحبا'))->message;
    pipelineRunJob($inbound->id);

    $reply = Message::where('direction', MessageDirection::Outbound)->first();

    // Placeholder greeting for "مرحبا" — no external call is made.
    expect($reply->text_content)->toContain('سَنَد');
    Http::assertNothingSent();
});

it('does not crash the pipeline on a permanent AI failure — sends the safe fallback', function () {
    aiConfigure(['ai.failure_behavior' => 'retry']);
    fakeGroq('', 401); // non-retryable

    $account = pipelineWebAccount();
    $inbound = app(MessageProcessor::class)->process(pipelineInbound($account, 'ext-ai-3', 'مرحبا'))->message;
    pipelineRunJob($inbound->id);

    $reply = Message::where('direction', MessageDirection::Outbound)->first();

    expect($reply->text_content)->toBe(config('ai.fallback_message'));
});
