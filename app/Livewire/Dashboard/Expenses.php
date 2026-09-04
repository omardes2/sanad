<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Expense;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('المصروفات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Expenses extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.dashboard.expenses', [
            'expenses' => Expense::query()
                ->with('user')
                ->latest('expense_date')
                ->paginate(15),
        ]);
    }
}
