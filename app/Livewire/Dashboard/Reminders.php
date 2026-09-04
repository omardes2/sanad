<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Reminder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('التذكيرات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Reminders extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.dashboard.reminders', [
            'reminders' => Reminder::query()
                ->with('user')
                ->latest('remind_at')
                ->paginate(15),
        ]);
    }
}
