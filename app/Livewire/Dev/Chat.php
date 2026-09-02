<?php

declare(strict_types=1);

namespace App\Livewire\Dev;

use App\Data\InboundMessageData;
use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageType;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Local chat simulator for exercising the message pipeline end to end without
 * WhatsApp. Available only in local/testing (the route + this guard enforce
 * a 404 elsewhere).
 */
#[Title('محاكي المحادثة — سَنَد')]
#[Layout('components.layouts.app')]
class Chat extends Component
{
    public ?int $selectedUserId = null;

    public ?int $conversationId = null;

    #[Validate('required|string|max:2000')]
    public string $body = '';

    public function mount(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $first = User::query()->orderBy('id')->first();

        if ($first !== null) {
            $this->selectUser($first->id);
        }
    }

    public function selectUser(int $userId): void
    {
        $this->selectedUserId = $userId;
        $this->conversationId = $this->resolveConversation($userId)?->id;
    }

    public function send(MessageProcessor $processor): void
    {
        $this->validate();

        $user = User::findOrFail($this->selectedUserId);
        $account = $this->webAccountFor($user);
        $this->conversationId = $this->resolveConversation($user->id)?->id;

        $processor->process(new InboundMessageData(
            channel: ChannelType::Web,
            externalMessageId: 'web-'.Str::uuid()->toString(),
            externalUserId: $account->external_identifier,
            type: MessageType::Text,
            text: $this->body,
            metadata: ['source' => 'dev.chat'],
            receivedAt: CarbonImmutable::now(),
        ));

        $this->body = '';
    }

    private function webAccountFor(User $user): ChannelAccount
    {
        return ChannelAccount::firstOrCreate(
            ['channel' => ChannelType::Web, 'external_identifier' => 'web-user-'.$user->id],
            [
                'user_id' => $user->id,
                'display_name' => $user->name,
                'status' => ChannelAccountStatus::Active,
            ],
        );
    }

    private function resolveConversation(int $userId): ?Conversation
    {
        $user = User::find($userId);

        if ($user === null) {
            return null;
        }

        $account = $this->webAccountFor($user);

        return Conversation::firstOrCreate(
            [
                'user_id' => $user->id,
                'channel_account_id' => $account->id,
                'status' => ConversationStatus::Active,
            ],
            ['last_message_at' => now()],
        );
    }

    public function render()
    {
        $messages = $this->conversationId !== null
            ? Message::query()->where('conversation_id', $this->conversationId)->orderBy('id')->get()
            : collect();

        return view('livewire.dev.chat', [
            'users' => User::query()->orderBy('id')->get(),
            'messages' => $messages,
        ]);
    }
}
