<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Conversation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('المحادثات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Conversations extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.dashboard.conversations', [
            'conversations' => Conversation::query()
                ->with(['user', 'channelAccount'])
                ->withCount('messages')
                ->latest('last_message_at')
                ->paginate(15),
        ]);
    }
}
