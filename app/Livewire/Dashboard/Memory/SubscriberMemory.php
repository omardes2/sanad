<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Memory;

use App\Enums\MemoryCategory;
use App\Enums\MemoryProvenance;
use App\Enums\ToolCapability;
use App\Models\User;
use App\Services\Memory\MemoryOperationsQuery;
use App\Services\Memory\MemoryService;
use App\Services\Tools\ToolConsentService;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One subscriber's memory METADATA: how many, which categories, which
 * provenance, how close to the ceiling, whether the rows can be opened at all,
 * and the two consents that govern the feature for them.
 *
 * Readability is asked PER SUBSCRIBER, and that is not an arbitrary choice:
 * `MemoryService::readable()` works from this subscriber's bounded active set,
 * so the answer costs one capped decryption pass. A platform-wide equivalent
 * would be a decryption scan across every subscriber, which the memory phase
 * explicitly forbade — so the overview page does not offer one rather than
 * inventing a number that looks like it.
 *
 * Still no content, and still no fingerprint.
 */
#[Title('ذاكرة مشترك | سَنَد')]
#[Layout('components.layouts.dashboard')]
class SubscriberMemory extends Component
{
    use WithPagination;

    public User $subscriber;

    #[Url]
    public string $category = '';

    #[Url]
    public string $provenance = '';

    #[Url]
    public string $state = '';

    #[Url]
    public string $importance = '';

    public function mount(User $subscriber): void
    {
        abort_unless(auth()->user()?->can(Permission::MemoryOperationsView->value) ?? false, 403);

        $this->subscriber = $subscriber;
    }

    public function updated(string $property): void
    {
        if (in_array($property, MemoryOperationsQuery::FILTERS, true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [
            'category' => $this->category,
            'provenance' => $this->provenance,
            'state' => $this->state,
            'importance' => $this->importance,
        ];
    }

    /**
     * @param  list<MemoryCategory|MemoryProvenance>  $cases
     * @return array<string, string>
     */
    private static function labels(array $cases): array
    {
        $labels = [];

        foreach ($cases as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    public function render(MemoryService $memory, ToolConsentService $consents)
    {
        $summary = MemoryOperationsQuery::summaryFor($this->subscriber);

        return view('livewire.dashboard.memory.subscriber', [
            'summary' => $summary,
            'rows' => MemoryOperationsQuery::rowsFor($this->subscriber, $this->filters()),
            'available' => $memory->available(),
            // Bounded by construction: this subscriber's active set, capped.
            'readable' => $memory->available() ? $memory->readable($this->subscriber) : false,
            'readConsent' => $consents->state((int) $this->subscriber->getKey(), ToolCapability::MemoryRead),
            'writeConsent' => $consents->state((int) $this->subscriber->getKey(), ToolCapability::MemoryWrite),
            // Built here rather than by widening MemoryCategory::options(),
            // which is the FROZEN option list inside the memory tool schemas.
            'categories' => self::labels(MemoryCategory::cases()),
            'provenances' => self::labels(MemoryProvenance::cases()),
            'canSeeConsents' => auth()->user()?->can(Permission::ToolsConsentsView->value) ?? false,
        ]);
    }
}
