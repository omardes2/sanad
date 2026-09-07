<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Fx\ReportingConversionInput;
use App\Enums\FxSubjectType;
use App\Livewire\Dashboard\Finance\Concerns\HandlesFxActions;
use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Services\Fx\FxRateBook;
use App\Services\Fx\ReportingConversionService;
use App\Support\Rbac\Permission;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One conversion scope = (subject, purpose, target currency) — its CURRENT
 * frozen conversion and the full append-only CORRECTION history (Phase
 * E5.2c).
 *
 * A correction appends a new frozen revision that supersedes the pointer;
 * no revision is ever edited, and no revision is ever recomputed — not when
 * a rate is corrected, not when the reporting currency changes. The
 * concurrency contract is the pointer this page rendered (hidden field): a
 * conversion frozen meanwhile ⇒ STATE CHANGED and the user decides again.
 */
#[Title('نطاق تحويل التقرير | سَنَد')]
#[Layout('components.layouts.dashboard')]
class FxConversionScopeDetail extends Component
{
    use HandlesFxActions;

    public int $scopeId;

    public string $expectedId = '';

    public string $scopeToken = 'c:0';

    public string $convKey = '';

    public string $convRateId = '';

    public string $convReason = '';

    public bool $confirming = false;

    public ?string $notice = null;

    public function mount(FxConversionScope $scope): void
    {
        $this->authorizeManage();
        $this->scopeId = $scope->id;
        $this->convKey = self::freshKey();
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

    public function correctConversion(ReportingConversionService $service): void
    {
        $expected = $this->expectedId; // rendered pointer

        $ok = $this->attempt('conversion', $this->convKey, function () use ($service, $expected): void {
            $scope = $this->scope();
            $this->assertRenderedToken($this->scopeToken, $scope->stateToken());

            $conversion = $service->convert(new ReportingConversionInput(
                subjectType: $scope->subject_type, subjectId: $scope->subject_id, targetCurrency: $scope->target_currency,
                fxRateId: $this->positiveInt($this->convRateId, 'معرّف السعر (fx_rate_id)'),
                expectedCurrentConversionId: $expected === '' ? null : $this->positiveInt($expected, 'التحويل الحالي المتوقع'),
                reasonCode: self::optional($this->convReason),
            ));

            $this->notice = "جُمِّدت المراجعة #{$conversion->id}: {$conversion->sourceAmountAtScale()} {$conversion->source_currency} → {$conversion->targetAmountAtScale()} {$conversion->target_currency} بالسعر #{$conversion->fx_rate_id} ({$conversion->direction->value})"
                .($conversion->supersedes_id ? " — تحلّ محل #{$conversion->supersedes_id} الذي يبقى كما جُمِّد." : '.');
        });

        if ($ok) {
            $this->reset('convRateId', 'convReason', 'confirming');
            $this->convKey = self::freshKey();
            $this->refreshRecord();
        }
    }

    public function render(FxRateBook $rates)
    {
        $this->authorizeManage();
        $user = auth()->user();
        $scope = $this->scope();
        $type = FxSubjectType::from($scope->subject_type);
        $revisions = FxConversion::query()->where('scope_id', $scope->id)->with('rate:id,scope_id')->orderByDesc('id')->get(); // fx_conversions_scope_idx + one eager load (never a query per row)

        /** @var Model|null $row */
        $row = $type->modelClass()::query()->find($scope->subject_id);
        $sourceCurrency = $row === null ? null : (string) $row->getAttribute('currency');
        $policyDate = $row === null ? null : $type->policyDate($row)->format('Y-m-d');

        // Quotes for EXACTLY the policy date, only while the correction form is open — a picking aid, never an auto-selection.
        $quotes = $this->confirming && $row !== null && $sourceCurrency !== $scope->target_currency
            ? $rates->quotesFor($sourceCurrency, $scope->target_currency, $policyDate)
            : [];

        return view('livewire.dashboard.finance.fx-conversion-scope-detail', [
            'scope' => $scope,
            'revisions' => $revisions,
            'current' => $scope->current_conversion_id === null ? null : $revisions->firstWhere('id', $scope->current_conversion_id),
            'subjectMissing' => $row === null,
            'sourceCurrency' => $sourceCurrency,
            'policyDate' => $policyDate,
            'status' => $sourceCurrency === null ? '—' : ($sourceCurrency === $scope->target_currency ? 'NATIVE' : ($scope->current_conversion_id === null ? 'NOT CONVERTED' : 'CONVERTED')),
            'quotes' => $quotes,
            'canAudit' => (bool) $user->can(Permission::AuditView->value),
            'auditUrl' => route('dashboard.audit', ['subject_type' => self::auditSubject($type), 'subject_id' => $scope->subject_id]),
        ]);
    }

    /** `fx.converted` is audited against the SUBJECT model, not the scope. */
    private static function auditSubject(FxSubjectType $type): string
    {
        return class_basename($type->modelClass());
    }

    private function scope(): FxConversionScope
    {
        return FxConversionScope::query()->findOrFail($this->scopeId);
    }

    protected function refreshRecord(): void
    {
        $scope = $this->scope();
        $this->expectedId = (string) ($scope->current_conversion_id ?? '');
        $this->scopeToken = $scope->stateToken();
    }
}
