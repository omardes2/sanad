<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\Launch\LaunchReadiness as ReadinessService;
use App\Services\Launch\OperationalWarnings;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The V1 Launch Readiness board.
 *
 * READ-ONLY BY CONSTRUCTION: no action methods, no forms, no writes. During the
 * week this page matters most, an operator must be able to open it without any
 * chance of changing the thing they came to measure.
 *
 * Two lists, kept apart on the screen exactly as they are in the services:
 * LAUNCH BLOCKERS come from gates the registry marks required, and OPERATIONAL
 * WARNINGS are counts that nobody has approved as release authority. Merging
 * them would let an unapproved threshold quietly decide whether Sanad ships.
 */
#[Title('جاهزية الإطلاق V1 | سَنَد')]
#[Layout('components.layouts.dashboard')]
class LaunchReadiness extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::LaunchReadinessView->value) ?? false, 403);
    }

    public function render(ReadinessService $readiness, OperationalWarnings $warnings)
    {
        // Evaluate ONCE and pass the result down: `blockers()` and `summary()`
        // both accept the evaluated list so the checks do not run three times.
        $statuses = $readiness->evaluate();

        return view('livewire.dashboard.launch-readiness', [
            'required' => array_values(array_filter($statuses, static fn ($s): bool => $s->gate->requiredForV1)),
            'deferred' => array_values(array_filter($statuses, static fn ($s): bool => $s->isDeferred())),
            'blockers' => $readiness->blockers($statuses),
            'summary' => $readiness->summary($statuses),
            'warnings' => $warnings->all(),
            'warningWindowDays' => OperationalWarnings::WINDOW_DAYS,
        ]);
    }
}
