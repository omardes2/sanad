<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Fx\RecordRateInput;
use App\Livewire\Dashboard\Finance\Concerns\HandlesFxActions;
use App\Models\FxConversion;
use App\Models\FxRate;
use App\Models\FxRateScope;
use App\Services\Fx\FxRateBook;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One quote scope = one (pair, rate_date) — its CURRENT revision and the full
 * append-only revision history (Phase E5.2c).
 *
 * A correction never edits a revision: it appends a new one that supersedes
 * the current pointer. The concurrency contract is the pointer this page
 * rendered (hidden field): a revision recorded meanwhile ⇒ STATE CHANGED,
 * the pointer is refreshed and the user decides again — never an automatic
 * retry.
 *
 * Conversions already frozen on a superseded revision are NEVER recomputed;
 * they are listed here exactly as frozen so the user can see what a
 * correction does and does not touch.
 */
#[Title('نطاق سعر الصرف | سَنَد')]
#[Layout('components.layouts.dashboard')]
class FxRateScopeDetail extends Component
{
    use HandlesFxActions;

    public int $scopeId;

    /** The pointer this page rendered ('' = no quote yet) — the service's concurrency contract. */
    public string $expectedId = '';

    public string $scopeToken = 'x:0';

    public string $rateKey = '';

    public string $rateValue = '';

    public string $rateEvidence = '';

    public string $rateReason = '';

    public bool $confirming = false;

    public ?string $notice = null;

    public function mount(FxRateScope $scope): void
    {
        $this->authorizeManage();
        $this->scopeId = $scope->id;
        $this->rateKey = self::freshKey();
        $this->refreshRecord();
    }

    public function openConfirm(): void
    {
        $this->authorizeManage();
        $this->confirming = true;
    }

    public function closeConfirm(): void
    {
        $this->confirming = false;
    }

    public function correctRate(FxRateBook $book): void
    {
        $expected = $this->expectedId; // the rendered pointer, never re-read before the call

        $ok = $this->attempt('rate', $this->rateKey, function () use ($book, $expected): void {
            $scope = $this->scope();
            $this->assertRenderedToken($this->scopeToken, $scope->stateToken());
            $pair = $scope->pair;

            $rate = $book->record(new RecordRateInput(
                baseCurrency: $pair->base_currency, quoteCurrency: $pair->quote_currency, rateDate: $scope->rate_date->format('Y-m-d'),
                rate: $this->rateValue, evidenceRef: $this->rateEvidence,
                expectedCurrentRateId: $expected === '' ? null : $this->positiveInt($expected, 'المراجعة الحالية المتوقعة'),
                reasonCode: self::optional($this->rateReason),
            ));

            $this->notice = "سُجِّلت المراجعة #{$rate->id}: 1 {$rate->base_currency} = {$rate->rate} {$rate->quote_currency} بتاريخ {$rate->rateDate()}"
                .($rate->supersedes_id ? " (تحلّ محل #{$rate->supersedes_id})" : '')
                .'. التحويلات المجمَّدة على المراجعة السابقة لم تتغيّر؛ صحّح كلًّا منها صراحةً إن لزم.';
        });

        if ($ok) {
            $this->reset('rateValue', 'rateEvidence', 'rateReason', 'confirming');
            $this->rateKey = self::freshKey();
            $this->refreshRecord();
        }
    }

    public function render()
    {
        $this->authorizeManage();
        $user = auth()->user();
        $scope = $this->scope();
        $revisions = FxRate::query()->where('scope_id', $scope->id)->orderByDesc('id')->get(); // fx_rates_scope_idx

        // Frozen conversions that name one of these revisions — listed, never recomputed.
        $frozen = $revisions->isEmpty() ? collect() : FxConversion::query()->whereIn('fx_rate_id', $revisions->pluck('id')->all())->orderByDesc('id')->limit(100)->get();

        return view('livewire.dashboard.finance.fx-rate-scope-detail', [
            'scope' => $scope,
            'pair' => $scope->pair,
            'revisions' => $revisions,
            'current' => $scope->current_rate_id === null ? null : $revisions->firstWhere('id', $scope->current_rate_id),
            'frozen' => $frozen,
            'canAudit' => (bool) $user->can(Permission::AuditView->value),
            'auditUrl' => route('dashboard.audit', ['subject_type' => 'FxRateScope', 'subject_id' => $scope->id]),
        ]);
    }

    private function scope(): FxRateScope
    {
        return FxRateScope::query()->with('pair')->findOrFail($this->scopeId);
    }

    protected function refreshRecord(): void
    {
        $scope = $this->scope();
        $this->expectedId = (string) ($scope->current_rate_id ?? '');
        $this->scopeToken = $scope->stateToken();
    }
}
