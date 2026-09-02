<?php

declare(strict_types=1);

use App\Agents\PlaceholderAgentOrchestrator;
use App\Contracts\AgentOrchestrator;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function agentReplyFor(string $text): string
{
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create(['user_id' => $user->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'text_content' => $text,
    ]);

    return app(PlaceholderAgentOrchestrator::class)->handle($user, $conversation, $message)->text;
}

it('greets when the message is مرحبا', function () {
    expect(agentReplyFor('مرحبا'))
        ->toBe('أهلًا! أنا سَنَد، مساعدك الشخصي الذكي. كيف بقدر أساعدك؟');
});

it('echoes any other message', function () {
    expect(agentReplyFor('سجل مصروف'))->toBe('تم استلام رسالتك: سجل مصروف');
});

it('is bound as the default AgentOrchestrator', function () {
    expect(app(AgentOrchestrator::class))->toBeInstanceOf(PlaceholderAgentOrchestrator::class);
});

it('returns a text response type', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create(['user_id' => $user->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'text_content' => 'hi',
    ]);

    expect(app(AgentOrchestrator::class)->handle($user, $conversation, $message)->type)
        ->toBe(MessageType::Text);
});
