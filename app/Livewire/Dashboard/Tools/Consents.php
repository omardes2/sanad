<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Tools;

use App\Enums\ToolCapability;
use App\Enums\ToolConsentStatus;
use App\Services\Tools\ToolConsentQuery;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tool consent state, per subscriber per capability.
 *
 * Read-only list. The only mutation anywhere in this phase is a REVOKE on the
 * detail page; there is no grant action here or anywhere else, because consent
 * can only be created by the subscriber themself — no operator permission,
 * console run, job or provider may manufacture it on their behalf, and
 * `ToolAuthorization::assertMayGrantConsent` enforces that below the UI.
 */
#[Title('موافقات الأدوات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Consents extends Component
{
    use WithPagination;

    #[Url]
    public string $capability = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $subscriber_id = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::ToolsConsentsView->value) ?? false, 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ToolConsentQuery::FILTERS, true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [
            'capability' => $this->capability,
            'status' => $this->status,
            'subscriber_id' => $this->subscriber_id,
        ];
    }

    public function render()
    {
        $query = ToolConsentQuery::build($this->filters());

        return view('livewire.dashboard.tools.consents', [
            'consents' => (clone $query)
                ->with('subscriber:id,name')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->paginate(25),
            'totals' => ToolConsentQuery::totals(),
            'capabilities' => ToolCapability::options(),
            'statuses' => [
                ToolConsentStatus::Granted->value => ToolConsentStatus::Granted->label(),
                ToolConsentStatus::Revoked->value => ToolConsentStatus::Revoked->label(),
            ],
        ]);
    }
}
