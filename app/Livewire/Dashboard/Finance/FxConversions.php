<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Fx\ReportingConversionInput;
use App\Enums\FxConversionPurpose;
use App\Enums\FxSubjectType;
use App\Exceptions\Fx\FxRuleException;
use App\Livewire\Dashboard\Finance\Concerns\HandlesFxActions;
use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Services\Fx\FxPairBook;
use App\Services\Fx\FxRateBook;
use App\Services\Fx\ReportingConversionService;
use App\Services\Fx\ReportingCurrencyService;
use App\Support\Rbac\Permission;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Frozen reporting conversions (Phase E3 → E5.2c operational UI) under
 * `finance.fx.manage`. One row per (subject, purpose, target) scope with its
 * CURRENT conversion; the correction history lives on the scope detail page.
 *
 * Converting is EXPLICIT end to end: the user loads the subject on demand
 * (its currency, its policy date, its NATIVE / CONVERTED / NOT CONVERTED
 * state and the pointer this page renders), then picks ONE `fx_rate_id`
 * from the quotes recorded for exactly that policy date. Nothing here
 * searches for a latest / nearest / fallback rate, and a native subject is
 * never given a rate-1 conversion.
 */
#[Title('تحويلات التقرير | سَنَد')]
#[Layout('components.layouts.dashboard')]
class FxConversions extends Component
{
    use HandlesFxActions;
    use WithPagination;

    public const PER_PAGE = 25;

    // ---- filters (URL) ----
    #[Url]
    public string $type = '';

    #[Url]
    public string $target = '';

    // ---- convert ----
    public string $convKey = '';

    public string $convSubjectType = 'customer_payment';

    public string $convSubjectId = '';

    public string $convTarget = '';

    public string $convRateId = '';

    public string $convReason = '';

    /** The pointer the loaded subject panel rendered ('' = none) — the service's concurrency contract. */
    public string $expectedId = '';

    /** On-demand resolution of ONE subject: page state, read-only, never cached. */
    public ?array $subject = null;

    public bool $confirming = false;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManage();
        $this->convKey = self::freshKey();
        $this->convTarget = app(ReportingCurrencyService::class)->current();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'target'], true)) {
            $this->resetPage();
        }

        if (in_array($property, ['convSubjectType', 'convSubjectId', 'convTarget'], true)) {
            $this->subject = null; // a changed identity invalidates the loaded facts
            $this->expectedId = '';
            $this->convRateId = '';
            $this->confirming = false;
        }
    }

    public function closeConfirm(): void
    {
        $this->confirming = false;
    }

    /**
     * Read the subject and the quotes recorded for its policy date — on
     * demand, read-only, nothing written. The user still picks the id.
     */
    public function loadSubject(FxRateBook $rates): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();
        $this->notice = null;
        $this->subject = null;
        $this->expectedId = '';
        $this->convRateId = '';
        $this->confirming = false;

        try {
            $type = FxSubjectType::tryFrom(trim($this->convSubjectType)) ?? throw new InvalidArgumentException('نوع الموضوع غير معروف.');
            $target = FxPairBook::currency($this->convTarget, 'target_currency');
            $id = $this->positiveInt($this->convSubjectId, 'معرّف الموضوع');
            /** @var Model|null $row */
            $row = $type->modelClass()::query()->find($id);

            if ($row === null) {
                throw new InvalidArgumentException('الموضوع غير موجود.');
            }
        } catch (FxRuleException $e) {
            $this->addError('conversion.rule', $e->rule.' — '.$e->getMessage());

            return;
        } catch (InvalidArgumentException $e) {
            $this->addError('conversion.validation', $e->getMessage());

            return;
        }

        $source = (string) $row->getAttribute('currency');
        $policyDate = $type->policyDate($row)->format('Y-m-d');
        $scope = self::scopeOf($type, $id, $target);
        $current = $scope?->current_conversion_id === null ? null : FxConversion::query()->find($scope->current_conversion_id);

        $this->expectedId = (string) ($current?->id ?? '');
        $this->subject = [
            'type' => $type->value, 'id' => $id, 'source_currency' => $source, 'target' => $target, 'policy_date' => $policyDate,
            'amount' => (string) $row->getAttribute($type->amountField()), 'scale' => $type->scale(),
            'status' => $source === $target ? 'NATIVE' : ($current === null ? 'NOT CONVERTED' : 'CONVERTED'),
            'scope_id' => $scope?->id,
            'current' => $current === null ? null : ['id' => $current->id, 'fx_rate_id' => $current->fx_rate_id, 'target_amount' => $current->targetAmountAtScale(), 'direction' => $current->direction->value],
            // Quotes recorded for EXACTLY this policy date, current revisions only — a picking aid, never an auto-selection.
            'quotes' => $source === $target ? [] : array_map(
                static fn ($rate): array => ['id' => $rate->id, 'pair' => $rate->base_currency.'/'.$rate->quote_currency, 'rate' => (string) $rate->rate, 'date' => $rate->rateDate(), 'evidence' => $rate->evidence_ref],
                $rates->quotesFor($source, $target, $policyDate),
            ),
        ];
    }

    public function openConfirm(): void
    {
        $this->authorizeManage();
        $this->confirming = $this->subject !== null;
    }

    public function convert(ReportingConversionService $service): void
    {
        $expected = $this->expectedId; // rendered pointer

        $ok = $this->attempt('conversion', $this->convKey, function () use ($service, $expected): void {
            $subject = $this->subject ?? throw new InvalidArgumentException('حمّل الموضوع أولًا لتختار سعرًا لتاريخ سياسته.');

            $conversion = $service->convert(new ReportingConversionInput(
                subjectType: $subject['type'], subjectId: $subject['id'], targetCurrency: $subject['target'],
                fxRateId: $this->positiveInt($this->convRateId, 'معرّف السعر (fx_rate_id)'),
                expectedCurrentConversionId: $expected === '' ? null : $this->positiveInt($expected, 'التحويل الحالي المتوقع'),
                reasonCode: self::optional($this->convReason),
            ));

            $this->notice = "جُمِّد التحويل #{$conversion->id}: {$conversion->subject_type} #{$conversion->subject_id} — {$conversion->sourceAmountAtScale()} {$conversion->source_currency} → {$conversion->targetAmountAtScale()} {$conversion->target_currency} بالسعر #{$conversion->fx_rate_id} ({$conversion->direction->value}، تاريخ {$conversion->fx_rate_date->format('Y-m-d')})."
                .($conversion->supersedes_id ? " يحلّ محل #{$conversion->supersedes_id}." : '');
        });

        if ($ok) {
            $this->reset('convRateId', 'convReason', 'confirming', 'subject');
            $this->expectedId = '';
            $this->convKey = self::freshKey();
        }
    }

    public function render(ReportingCurrencyService $reporting)
    {
        $this->authorizeManage();
        $user = auth()->user();
        $type = FxSubjectType::tryFrom(strtolower(trim($this->type)))?->value;
        $target = strtoupper(trim($this->target));
        $target = preg_match('/^[A-Z]{3}$/', $target) === 1 ? $target : null;

        $query = FxConversionScope::query()->where('purpose', FxConversionPurpose::Reporting->value)->orderByDesc('id');

        if ($type !== null) {
            $query->where('subject_type', $type); // prefix of fx_conversion_scopes_scope_unique
        }
        if ($target !== null) {
            $query->where('target_currency', $target);
        }

        $scopes = $query->paginate(self::PER_PAGE);

        // Two grouped lookups for the whole page — never one query per row.
        $current = FxConversion::query()->whereIn('id', $scopes->pluck('current_conversion_id')->filter()->all())->get()->keyBy('id');
        $revisions = FxConversion::query()->selectRaw('scope_id, COUNT(*) AS revisions')->whereIn('scope_id', $scopes->pluck('id')->all())->groupBy('scope_id')->get()->keyBy('scope_id');

        return view('livewire.dashboard.finance.fx-conversions', [
            'scopes' => $scopes,
            'current' => $current,
            'revisions' => $revisions,
            'types' => array_map(static fn (FxSubjectType $t): string => $t->value, FxSubjectType::cases()),
            'reportingCurrency' => $reporting->current(),
            'canAudit' => (bool) $user->can(Permission::AuditView->value),
            'auditUrl' => route('dashboard.audit', ['action' => 'fx.converted']),
        ]);
    }

    private static function scopeOf(FxSubjectType $type, int $subjectId, string $target): ?FxConversionScope
    {
        return FxConversionScope::query()->where('subject_type', $type->value)->where('subject_id', $subjectId)
            ->where('purpose', FxConversionPurpose::Reporting->value)->where('target_currency', $target)->first();
    }

    protected function refreshRecord(): void
    {
        if ($this->subject === null) {
            return;
        }

        $type = FxSubjectType::from($this->subject['type']);
        $scope = self::scopeOf($type, $this->subject['id'], $this->subject['target']);
        $current = $scope?->current_conversion_id === null ? null : FxConversion::query()->find($scope->current_conversion_id);
        $this->expectedId = (string) ($current?->id ?? '');
        $this->subject['scope_id'] = $scope?->id;
        $this->subject['status'] = $this->subject['source_currency'] === $this->subject['target'] ? 'NATIVE' : ($current === null ? 'NOT CONVERTED' : 'CONVERTED');
        $this->subject['current'] = $current === null ? null : ['id' => $current->id, 'fx_rate_id' => $current->fx_rate_id, 'target_amount' => $current->targetAmountAtScale(), 'direction' => $current->direction->value];
    }
}
