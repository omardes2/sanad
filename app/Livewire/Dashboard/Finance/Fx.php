<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Fx\RecordRateInput;
use App\Data\Fx\ReportingConversionInput;
use App\Exceptions\Fx\FxRuleException;
use App\Exceptions\Fx\StaleFxException;
use App\Models\FxPair;
use App\Models\FxRate;
use App\Services\Fx\FxPairBook;
use App\Services\Fx\FxRateBook;
use App\Services\Fx\ReportingConversionService;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Fx\ReportingView;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Minimal admin page for Phase E3 under `finance.fx.manage`: Create FX Pair ·
 * Record Rate for Date · Correct/Supersede Rate (same form with the expected
 * current id) · Convert subject for Reporting (explicit fx_rate_id) · Set
 * Reporting Currency (typed code), plus the reporting-currency view of cash
 * and cost. `finance.view` alone can read converted values on the finance
 * pages but never creates a conversion. Mount and every action re-authorise.
 */
#[Title('أسعار الصرف وعملة التقرير | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Fx extends Component
{
    // ---- Pair ----
    public string $pairBase = '';

    public string $pairQuote = '';

    // ---- Rate ----
    public string $rateBase = '';

    public string $rateQuote = '';

    public string $rateDate = '';

    public string $rateValue = '';

    public string $rateEvidence = '';

    public string $rateReason = '';

    public string $rateExpected = '';

    // ---- Conversion ----
    public string $convSubjectType = 'customer_payment';

    public string $convSubjectId = '';

    public string $convTarget = '';

    public string $convRateId = '';

    public string $convExpected = '';

    public string $convReason = '';

    // ---- Reporting currency ----
    public string $rcCode = '';

    public string $rcTyped = '';

    public string $rcReason = '';

    // ---- Views ----
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $fromMonth = '';

    #[Url]
    public string $toMonth = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeFx();
        $now = CarbonImmutable::now('UTC');
        $this->rateDate = $now->format('Y-m-d');

        if ($this->from === '' || $this->to === '') {
            $this->to = $now->format('Y-m-d');
            $this->from = $now->subDays(29)->format('Y-m-d');
        }

        if ($this->fromMonth === '' || $this->toMonth === '') {
            $this->toMonth = $now->format('Y-m');
            $this->fromMonth = $now->subMonths(2)->format('Y-m');
        }
    }

    public function createPair(FxPairBook $book): void
    {
        $this->run('pair', function () use ($book): string {
            $pair = $book->create($this->pairBase, $this->pairQuote);
            $this->reset('pairBase', 'pairQuote');

            return "أُنشئ الزوج {$pair->pair_key} بالاتجاه الرسمي {$pair->base_currency}/{$pair->quote_currency} (1 {$pair->base_currency} = rate × {$pair->quote_currency}).";
        });
    }

    public function recordRate(FxRateBook $book): void
    {
        $this->run('rate', function () use ($book): string {
            $rate = $book->record(new RecordRateInput(
                baseCurrency: $this->rateBase, quoteCurrency: $this->rateQuote, rateDate: $this->rateDate, rate: $this->rateValue, evidenceRef: $this->rateEvidence,
                expectedCurrentRateId: trim($this->rateExpected) === '' ? null : $this->positiveInt($this->rateExpected, 'المراجعة الحالية المتوقعة'), reasonCode: self::optional($this->rateReason),
            ));
            $this->reset('rateValue', 'rateEvidence', 'rateReason');
            $this->rateExpected = (string) $rate->id;

            return "سُجِّل السعر #{$rate->id}: 1 {$rate->base_currency} = {$rate->rate} {$rate->quote_currency} بتاريخ {$rate->rateDate()}".($rate->supersedes_id ? " (يستبدل #{$rate->supersedes_id})" : '').'.';
        });
    }

    public function convert(ReportingConversionService $service): void
    {
        $this->run('conversion', function () use ($service): string {
            $conversion = $service->convert(new ReportingConversionInput(
                subjectType: $this->convSubjectType, subjectId: $this->positiveInt($this->convSubjectId, 'معرّف الموضوع'), targetCurrency: $this->convTarget,
                fxRateId: $this->positiveInt($this->convRateId, 'معرّف السعر'), expectedCurrentConversionId: trim($this->convExpected) === '' ? null : $this->positiveInt($this->convExpected, 'التحويل الحالي المتوقع'), reasonCode: self::optional($this->convReason),
            ));
            $this->reset('convReason');
            $this->convExpected = (string) $conversion->id;

            return "حُوِّل {$conversion->subject_type} #{$conversion->subject_id}: {$conversion->sourceAmountAtScale()} {$conversion->source_currency} → {$conversion->targetAmountAtScale()} {$conversion->target_currency} بالسعر #{$conversion->fx_rate_id} ({$conversion->direction->value}، تاريخ {$conversion->fx_rate_date->format('Y-m-d')}). التحويل #{$conversion->id}.";
        });
    }

    public function setReportingCurrency(ReportingCurrencyService $service): void
    {
        $this->run('reporting_currency', function () use ($service): string {
            $code = $service->change($this->rcCode, $this->rcTyped, self::optional($this->rcReason));
            $this->reset('rcCode', 'rcTyped', 'rcReason');

            return "عملة التقرير الآن {$code}. لم يُعَد حساب أي تحويل مجمَّد.";
        });
    }

    public function render(ReportingView $view, ReportingCurrencyService $reporting, FxRateBook $rates)
    {
        $this->authorizeFx();

        $cash = $cost = null;
        $cashError = $costError = null;

        try {
            [$from, $to] = Payments::window($this->from, $this->to);
            $cash = $view->cash($from, $to);
        } catch (InvalidArgumentException|FxRuleException $e) {
            $cashError = $e->getMessage();
        }

        try {
            $cost = $view->cost($this->fromMonth, $this->toMonth);
        } catch (\Throwable $e) {
            $costError = $e->getMessage();
        }

        return view('livewire.dashboard.finance.fx', [
            'reportingCurrency' => $reporting->current(),
            'pairs' => FxPair::query()->orderBy('pair_key')->get(),
            'rates' => FxRate::query()->orderByDesc('id')->limit(25)->get(),
            'cash' => $cash,
            'cashError' => $cashError,
            'cost' => $cost,
            'costError' => $costError,
        ]);
    }

    private function run(string $bag, callable $fn): void
    {
        $this->authorizeFx();
        $this->resetErrorBag($bag);
        $this->notice = null;

        try {
            $this->notice = $fn();
        } catch (FxRuleException|StaleFxException|InvalidArgumentException $e) {
            $this->addError($bag, $e->getMessage());
        }
    }

    private function authorizeFx(): void
    {
        abort_unless(auth()->user()?->can(Permission::FinanceFxManage->value) ?? false, 403);
    }

    private static function optional(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    private function positiveInt(string $value, string $label): int
    {
        $value = trim($value);

        if (! ctype_digit($value) || (int) $value <= 0) {
            throw new InvalidArgumentException("{$label} يجب أن يكون رقمًا صحيحًا موجبًا.");
        }

        return (int) $value;
    }
}
