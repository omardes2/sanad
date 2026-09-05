<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\AuditLog;
use App\Support\Rbac\Permission;
use App\Support\Security\SecretRedactor;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only audit trail (Phase C0). Strict RBAC: the route requires
 * `audit.view` and the component re-checks it on mount (no legacy is_admin
 * bypass). Rows were redacted at write time; the redactor runs again on render
 * as a defensive second pass before anything reaches the browser.
 */
#[Title('سجل التدقيق | سَنَد')]
#[Layout('components.layouts.dashboard')]
class AuditLogs extends Component
{
    use WithPagination;

    #[Url]
    public string $action = '';

    #[Url]
    public string $actor = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::AuditView->value) ?? false, 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['action', 'actor', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $redactor = app(SecretRedactor::class);

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($this->action !== '', fn ($q) => $q->where('action', 'like', trim($this->action).'%'))
            ->when($this->actor !== '', fn ($q) => $q->where('actor', $this->actor))
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->to !== '', fn ($q) => $q->where('created_at', '<=', $this->to.' 23:59:59'))
            ->latest('id')
            ->paginate(25);

        // Defensive second pass — never trust that a row was redacted.
        $logs->getCollection()->transform(function (AuditLog $log) use ($redactor): AuditLog {
            $log->setAttribute('metadata', $redactor->redact($log->metadata ?? []));

            return $log;
        });

        return view('livewire.dashboard.audit-logs', ['logs' => $logs]);
    }
}
