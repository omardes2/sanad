<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Close\CloseEvaluation;
use App\Exceptions\Close\CloseBlockedException;
use App\Exceptions\Close\CloseRuleException;
use App\Exceptions\Close\StaleCloseException;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Models\FinancePeriodClose;
use App\Models\FinancePeriodCloseScope;
use App\Services\Close\ClosePreflight;
use App\Services\Close\PeriodCloseService;
use App\Services\Fx\ReportingCurrencyService;
use App\Support\Rbac\Permission;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Minimal admin page for Phase E4: preflight of one calendar month UTC
 * (conditions + the seven cash-basis figures), the close history with
 * DRIFT SINCE CLOSE, and the two super_admin-only actions Close (typed
 * "CLOSE YYYY-MM") and Reopen (typed "REOPEN YYYY-MM" + reason + evidence).
 * `finance.view` opens the page read-only; `finance.close_period` (super_admin
 * only) is re-checked on every action and again inside the service. Nothing
 * here is revenue, gross profit or margin.
 */
#[Title('إقفال الفترة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class PeriodClose extends Component
{
    #[Url]
    public string $month = '';

    public string $closeTyped = '';

    public string $closeKey = '';

    public string $reopenCloseId = '';

    public string $reopenTyped = '';

    public string $reopenReason = '';

    public string $reopenEvidence = '';

    public ?string $notice = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::FinanceView->value) ?? false, 403);

        if ($this->month === '') {
            $this->month = CarbonImmutable::now('UTC')->subMonth()->format('Y-m');
        }

        $this->closeKey = 'ui:'.Str::uuid()->toString();
    }

    public function close(PeriodCloseService $service): void
    {
        $this->authorizeClose();
        $this->resetErrorBag('close');
        $this->notice = null;

        try {
            $scope = $this->scope();
            $close = $service->close($this->month, $scope?->current_close_id, $this->closeKey, trim($this->closeTyped));
        } catch (CloseRuleException|CloseBlockedException|StaleCloseException|ReconciliationRuleException|InvalidArgumentException $e) {
            $this->addError('close', $e->getMessage());

            return;
        }

        $this->notice = "أُقفل الشهر {$close->month()} (السجل #{$close->id}، المراجعة {$close->revision}، hash ".substr((string) $close->input_hash, 0, 12).'…). Reconciled Cash Contribution: '.($close->reconciled_cash_contribution ?? 'NOT AVAILABLE').' '.$close->reporting_currency.'.';
        $this->reset('closeTyped');
        $this->closeKey = 'ui:'.Str::uuid()->toString();
    }

    public function reopen(PeriodCloseService $service): void
    {
        $this->authorizeClose();
        $this->resetErrorBag('reopen');
        $this->notice = null;

        try {
            $scope = $this->scope();
            $record = $service->reopen((int) $this->reopenCloseId, $scope?->current_close_id, $this->reopenReason, $this->reopenEvidence, trim($this->reopenTyped));
        } catch (CloseRuleException|StaleCloseException|ReconciliationRuleException|InvalidArgumentException $e) {
            $this->addError('reopen', $e->getMessage());

            return;
        }

        $this->notice = "أُعيد فتح الشهر {$record->month()} بالسجل #{$record->id} (الإقفال القديم #{$record->reopened_close_id} محفوظ بلا تعديل).";
        $this->reset('reopenCloseId', 'reopenTyped', 'reopenReason', 'reopenEvidence');
    }

    public function render(ClosePreflight $preflight, PeriodCloseService $service, ReportingCurrencyService $reporting)
    {
        abort_unless(auth()->user()?->can(Permission::FinanceView->value) ?? false, 403);

        $evaluation = null;
        $error = null;

        try {
            $evaluation = $preflight->evaluate($this->month);
        } catch (ReconciliationRuleException|InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        $scope = $this->scope();
        $history = $scope === null ? collect() : FinancePeriodClose::query()->where('scope_id', $scope->id)->orderByDesc('id')->get();
        $drift = [];

        foreach ($history as $record) {
            if ($scope !== null && $record->id === $scope->current_close_id) {
                $drift[$record->id] = $service->drift($record);
            }
        }

        return view('livewire.dashboard.finance.period-close', [
            'evaluation' => $evaluation,
            'error' => $error,
            'scope' => $scope,
            'history' => $history,
            'drift' => $drift,
            'reportingCurrency' => $reporting->current(),
            'canClose' => (bool) (auth()->user()?->can(Permission::FinanceClosePeriod->value) ?? false),
        ]);
    }

    public static function labelFor(CloseEvaluation $evaluation, string $key): string
    {
        return $evaluation->metrics[$key] ?? 'NOT AVAILABLE';
    }

    private function scope(): ?FinancePeriodCloseScope
    {
        try {
            [$start] = ReconciliationRules::month($this->month);
        } catch (ReconciliationRuleException) {
            return null;
        }

        return FinancePeriodCloseScope::query()->where('period_start', $start->format('Y-m-d H:i:s'))->where('reporting_currency', app(ReportingCurrencyService::class)->current())->first();
    }

    private function authorizeClose(): void
    {
        abort_unless(auth()->user()?->can(Permission::FinanceClosePeriod->value) ?? false, 403);
    }
}
