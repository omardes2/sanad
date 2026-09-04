<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Message;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('الرسائل | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Messages extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.dashboard.messages', [
            'messages' => Message::query()
                ->with(['user', 'conversation'])
                ->latest()
                ->paginate(20),
        ]);
    }
}
