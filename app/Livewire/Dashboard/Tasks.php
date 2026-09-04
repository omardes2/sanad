<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Task;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('المهام | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Tasks extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.dashboard.tasks', [
            'tasks' => Task::query()
                ->with('user')
                ->latest()
                ->paginate(15),
        ]);
    }
}
