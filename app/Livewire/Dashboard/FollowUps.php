<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\FollowUpBlockReason;
use App\Enums\FollowUpStatus;
use App\Services\FollowUps\FollowUpOperationsQuery;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Follow-up loops — METADATA ONLY.
 *
 * The subscriber's own wording of what is being followed up on is NOT rendered
 * here, and not because it is filtered out in the view: the query service this
 * page uses cannot select the column at all. Every operational question this page
 * exists to answer — which loops are live, which are held and why, how much ask
 * budget is spent, when the next ask is due — is answered without it.
 *
 * Reading the question text would need a content-level permission that this phase
 * deliberately does not create; it is proposed for review instead, so adding it is
 * a decision someone makes rather than a side effect of building a page.
 */
#[Title('المتابعات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class FollowUps extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $blocked_reason = '';

    #[Url]
    public string $subscriber_id = '';

    public function updated(string $property): void
    {
        if (in_array($property, FollowUpOperationsQuery::FILTERS, true)) {
            $this->resetPage();
        }
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return [
            'status' => $this->status,
            'blocked_reason' => $this->blocked_reason,
            'subscriber_id' => $this->subscriber_id,
        ];
    }

    public function render()
    {
        return view('livewire.dashboard.follow-ups', [
            'overview' => FollowUpOperationsQuery::overview(),
            'followUps' => FollowUpOperationsQuery::paginate($this->filters()),
            'statuses' => FollowUpStatus::options(),
            'reasons' => array_combine(
                FollowUpBlockReason::values(),
                array_map(static fn (FollowUpBlockReason $r): string => $r->label(), FollowUpBlockReason::cases()),
            ),
        ]);
    }
}
