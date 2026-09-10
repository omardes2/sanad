<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Tools;

use App\Enums\ToolCapability;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Services\Tools\ToolInvocationQuery;
use App\Support\Rbac\Permission;
use App\Support\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tool invocation history: what the model proposed, and what the server decided.
 *
 * Read-only. Modelled on the Usage page (Phase C2) down to the bounded window,
 * because `tool_invocations` is the table most likely to grow without bound and
 * an unbounded admin query over it is a production incident waiting for a
 * dropdown.
 *
 * Nothing here renders tool ARGUMENTS: `input` holds only the persistable subset
 * (empty for every tool shipped so far) and `input_fields` only field NAMES.
 * There is nothing to redact, because nothing was stored.
 */
#[Title('استدعاءات الأدوات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Invocations extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $subscriber_id = '';

    #[Url]
    public string $tool_key = '';

    #[Url]
    public string $capability = '';

    #[Url]
    public string $side_effect = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $failure_kind = '';

    #[Url]
    public string $refusal_reason = '';

    #[Url]
    public string $idempotency_key = '';

    #[Url]
    public string $input_hash = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::ToolsInvocationsView->value) ?? false, 403);

        if ($this->from === '' || $this->to === '') {
            $today = CarbonImmutable::today();
            $this->to = $today->format('Y-m-d');
            $this->from = $today->subDays(ToolInvocationQuery::DEFAULT_DAYS - 1)->format('Y-m-d');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', ...ToolInvocationQuery::FILTERS], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [
            'subscriber_id' => $this->subscriber_id,
            'tool_key' => $this->tool_key,
            'capability' => $this->capability,
            'side_effect' => $this->side_effect,
            'status' => $this->status,
            'failure_kind' => $this->failure_kind,
            'refusal_reason' => $this->refusal_reason,
            'idempotency_key' => $this->idempotency_key,
            'input_hash' => $this->input_hash,
        ];
    }

    public function render(ToolRegistry $registry)
    {
        $error = null;
        $invocations = null;
        $totals = null;

        try {
            [$from, $to] = ToolInvocationQuery::window($this->from, $this->to);
            $query = ToolInvocationQuery::build($from, $to, $this->filters());
            $totals = ToolInvocationQuery::totals($query);
            $invocations = (clone $query)
                ->with('subscriber:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        return view('livewire.dashboard.tools.invocations', [
            'invocations' => $invocations,
            'totals' => $totals,
            'error' => $error,
            'maxDays' => ToolInvocationQuery::MAX_DAYS,
            'toolKeys' => array_map(static fn ($d): string => $d->key->value(), $registry->all()),
            'capabilities' => ToolCapability::options(),
            'sideEffects' => ToolSideEffect::options(),
            'statuses' => ToolInvocationStatus::options(),
            'failureKinds' => ToolInvocationFailureKind::options(),
            'refusalReasons' => ToolInvocationRefusalReason::options(),
        ]);
    }
}
