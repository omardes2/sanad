<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Reporting\FrozenCloseDetail;
use App\Models\AuditLog;
use App\Models\FinancePeriodClose;
use App\Models\FinancePeriodCloseScope;
use App\Services\Close\PeriodCloseService;
use App\Services\Reporting\FrozenCloseReader;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Frozen close detail (Phase E5.1) — `finance.view`, read-only. Everything on
 * the page comes from finance_period_closes + finance_period_close_inputs
 * (via FrozenCloseReader): revision chain, period UTC, reporting currency,
 * the seven frozen figures, the frozen conditions, the canonical input hash
 * and the input rows grouped by type with their FX facts. No live preflight
 * is ever used to render a figure. CHECK CURRENT DRIFT is an explicit
 * on-demand action whose answer is shown next to — never instead of — the
 * frozen values. Audit trail links are read-only. No PII.
 */
#[Title('تفاصيل الإقفال | سَنَد')]
#[Layout('components.layouts.dashboard')]
class CloseDetail extends Component
{
    public int $closeId;

    /** null = not checked; true/false = the on-demand answer */
    public ?bool $drift = null;

    public function mount(FinancePeriodClose $close): void
    {
        abort_unless(auth()->user()?->can(Permission::FinanceView->value) ?? false, 403);
        $this->closeId = $close->id;
    }

    /** On-demand only: compares the live evaluation hash with the frozen one. Frozen values never change. */
    public function checkDrift(PeriodCloseService $service): void
    {
        abort_unless(auth()->user()?->can(Permission::FinanceView->value) ?? false, 403);
        $this->drift = $service->drift(FinancePeriodClose::query()->findOrFail($this->closeId));
    }

    public function render(FrozenCloseReader $reader)
    {
        $user = auth()->user();
        abort_unless($user?->can(Permission::FinanceView->value) ?? false, 403);

        $close = FinancePeriodClose::query()->findOrFail($this->closeId);
        $detail = $reader->detail($close);

        return view('livewire.dashboard.finance.close-detail', [
            'detail' => $detail,
            'close' => $close,
            'figures' => FrozenCloseReader::FIGURES,
            'auditEntries' => $this->auditEntries($detail),
            'canExport' => (bool) $user->can(Permission::FinanceExport->value),
            'canAudit' => (bool) $user->can(Permission::AuditView->value),
            'auditUrl' => route('dashboard.audit', ['subject_type' => class_basename(FinancePeriodCloseScope::class), 'subject_id' => $detail->scope->id]),
        ]);
    }

    /**
     * The close / reopen audit entries of this close's scope — read-only, redacted at write time.
     *
     * @return list<AuditLog>
     */
    private function auditEntries(FrozenCloseDetail $detail): array
    {
        return AuditLog::query()
            ->where('subject_type', $detail->scope->getMorphClass())->where('subject_id', $detail->scope->id)
            ->whereIn('action', [AuditActions::FinancePeriodClosed, AuditActions::FinancePeriodReopened])
            ->orderByDesc('id')->limit(50)->get(['id', 'action', 'actor', 'actor_ref', 'metadata', 'created_at'])->all();
    }
}
