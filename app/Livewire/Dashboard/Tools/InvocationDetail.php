<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Tools;

use App\Models\ToolInvocation;
use App\Support\Rbac\Permission;
use App\Support\Tools\ToolKey;
use App\Support\Tools\ToolOutputPersistence;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One invocation, its transition history, and the links out to the subscriber,
 * the message and the audit trail.
 *
 * THE OUTPUT RULE. For a tool whose output is redacted, the row holds a
 * SHAPE-ONLY PROJECTION — for `memory.read@2` that is `{memories_count,
 * truncated}` — and this page must never present it as "what the tool returned".
 * It is audit metadata about a result that was deliberately never stored, and
 * labelling it as the result would repeat, on a dashboard, exactly the mistake
 * the replay-semantics work removed from the executor. So a redacted tool gets
 * an explicit banner and the projection is labelled as storage metadata.
 */
#[Title('استدعاء أداة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class InvocationDetail extends Component
{
    public ToolInvocation $invocation;

    public function mount(ToolInvocation $invocation): void
    {
        abort_unless(auth()->user()?->can(Permission::ToolsInvocationsView->value) ?? false, 403);

        $this->invocation = $invocation;
    }

    public function render()
    {
        $key = ToolKey::of($this->invocation->tool_key, $this->invocation->tool_version);

        return view('livewire.dashboard.tools.invocation-detail', [
            'invocation' => $this->invocation->load(['subscriber:id,name', 'events']),
            // Asked of the code, never guessed from the shape of the row.
            'isRedacted' => ToolOutputPersistence::isRedacted($key),
            'rehydratable' => ToolOutputPersistence::rehydratableOnReplay($key),
            // Invocations are NOT audited (the append-only history is
            // `tool_invocation_events`), so this page offers no audit link for
            // the invocation itself — it would always come back empty. The
            // useful neighbouring question, "was consent changed around this
            // time?", is answered on the consent page, which IS audited.
            'canSeeConsents' => auth()->user()?->can(Permission::ToolsConsentsView->value) ?? false,
        ]);
    }
}
