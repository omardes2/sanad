<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Conversation;
use App\Models\Expense;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('نظرة عامة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Overview extends Component
{
    public function render()
    {
        return view('livewire.dashboard.overview', [
            'stats' => [
                'users' => User::query()->count(),
                'conversations' => Conversation::query()->count(),
                'messages' => Message::query()->count(),
                'tasks' => Task::query()->incomplete()->count(),
                'reminders' => Reminder::query()->due()->count(),
                'expenses' => Expense::query()->count(),
            ],
        ]);
    }
}
