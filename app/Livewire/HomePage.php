<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('سَنَد | SANAD')]
#[Layout('components.layouts.app')]
class HomePage extends Component
{
    public function render()
    {
        return view('livewire.home-page', [
            'appName' => config('sanad.name', 'SANAD'),
            'environment' => app()->environment(),
            'services' => [
                'postgres' => $this->serviceUp(fn () => DB::connection()->select('select 1')),
                'redis' => $this->serviceUp(fn () => Redis::connection()->ping()),
            ],
        ]);
    }

    /**
     * Safely probe a service. Never throws; returns a simple up/down flag.
     * No connection details or secrets are surfaced.
     */
    private function serviceUp(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
