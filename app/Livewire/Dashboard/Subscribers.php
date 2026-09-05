<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Subscribers = non-admin users (each owns a WhatsApp channel account). Shows
 * their plan and subscription status at a glance.
 */
#[Title('المشتركون | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Subscribers extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.dashboard.subscribers', [
            'subscribers' => User::query()
                ->where('is_admin', false)
                ->with(['subscription.plan'])
                ->latest('id')
                ->paginate(20),
        ]);
    }
}
