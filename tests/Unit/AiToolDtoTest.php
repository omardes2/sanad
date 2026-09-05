<?php

declare(strict_types=1);

use App\Data\Ai\AiMessage;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiRole;
use App\Data\Ai\AiToolCall;
use App\Data\Ai\AiToolDefinition;
use App\Data\Ai\ToolResult;
use App\Enums\AiOperation;

it('serialises a tool-role message with its tool_call_id', function () {
    $message = AiMessage::tool('call_1', '{"ok":true}');

    expect($message->role)->toBe(AiRole::Tool)
        ->and($message->toArray())->toBe(['role' => 'tool', 'content' => '{"ok":true}', 'tool_call_id' => 'call_1'])
        ->and(AiMessage::user('hi')->toArray())->toBe(['role' => 'user', 'content' => 'hi']);
});

it('turns a tool result into the message sent back to the model', function () {
    $call = new AiToolCall('call_9', 'create_task', ['title' => 'x']);

    $ok = ToolResult::ok($call, ['id' => 42])->toMessage();
    $failed = ToolResult::failed($call, 'invalid date')->toMessage();

    expect($ok->toolCallId)->toBe('call_9')
        ->and(json_decode($ok->content, true))->toBe(['ok' => true, 'result' => ['id' => 42]])
        ->and(json_decode($failed->content, true))->toBe(['ok' => false, 'error' => 'invalid date']);
});

it('binds a model and tools to a request immutably', function () {
    $base = new AiRequest([AiMessage::user('hi')], 0.5, 100, 10);
    $tool = new AiToolDefinition('create_reminder', 'ينشئ تذكيرًا');

    $routed = $base->withModel('gpt-4.1-mini')->withTools([$tool]);

    expect($base->model)->toBeNull()
        ->and($base->hasTools())->toBeFalse()
        ->and($routed->model)->toBe('gpt-4.1-mini')
        ->and($routed->tools[0]->name)->toBe('create_reminder')
        ->and($routed->operation)->toBe(AiOperation::Chat)
        ->and($routed->messages)->toBe($base->messages);
});
