<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Fx\RecordRateInput;
use App\Livewire\Dashboard\Finance\Concerns\HandlesFxActions;
use App\Models\FxPair;
use App\Models\FxRate;
use App\Models\FxRateScope;
use App\Services\Fx\FxRateBook;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Manual quotes (Phase E3 → E5.2c operational UI) under `finance.fx.manage`.
 *
 * The list is ONE row per (pair, date) scope with its CURRENT revision — the
 * revision history and every correction live on the scope detail page, where
 * the expected pointer is rendered. A quote is for its date: this page never
 * shows a "latest" or "nearest" rate for another date, and nothing here picks
 * a rate for a conversion.
 *
 * Filters are allowlisted, bounded and kept in the URL (pair key · UTC date
 * window ≤ 366 days); the order is `id desc` with 25 rows per page.
 */
#[Title('أسعار الصرف | سَنَد')]
#[Layout('components.layouts.dashboard')]
class FxRates extends Component
{
    use HandlesFxActions;
    use WithPagination;

    public const PER_PAGE = 25;

    public const MAX_DAYS = 366;

    // ---- filters (URL) ----
    #[Url]
    public string $pair = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    // ---- record a quote for a date that has none yet ----
    public string $rateKey = '';

    public string $rateBase = '';

    public string $rateQuote = '';

    public string $rateDate = '';

    public string $rateValue = '';

    public string $rateEvidence = '';

    public string $rateReason = '';

    public bool $confirming = false;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManage();
        $now = CarbonImmutable::now('UTC');
        $this->rateKey = self::freshKey();
        $this->rateDate = $now->format('Y-m-d');

        if ($this->from === '' || $this->to === '') {
            $this->to = $now->format('Y-m-d');
            $this->from = $now->subDays(89)->format('Y-m-d');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['pair', 'from', 'to'], true)) {
            $this->resetPage();
        }
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

    /**
     * A FIRST quote for (pair, date): the expected current revision is
     * explicitly "none". A date that already carries a quote is refused as
     * stale — corrections are made on the scope detail page, where the
     * revision being superseded is on screen.
     */
    public function recordRate(FxRateBook $book): void
    {
        $ok = $this->attempt('rate', $this->rateKey, function () use ($book): void {
            $rate = $book->record(new RecordRateInput(
                baseCurrency: $this->rateBase, quoteCurrency: $this->rateQuote, rateDate: $this->rateDate,
                rate: $this->rateValue, evidenceRef: $this->rateEvidence, expectedCurrentRateId: null, reasonCode: self::optional($this->rateReason),
            ));

            $this->notice = "سُجِّل السعر #{$rate->id}: 1 {$rate->base_currency} = {$rate->rate} {$rate->quote_currency} بتاريخ {$rate->rateDate()} (UTC). لتصحيحه افتح نطاق السعر.";
        });

        if ($ok) {
            $this->reset('rateValue', 'rateEvidence', 'rateReason', 'confirming');
            $this->rateKey = self::freshKey();
        }
    }

    public function render()
    {
        $this->authorizeManage();
        $user = auth()->user();
        $windowError = null;
        $window = null;

        try {
            $window = self::window($this->from, $this->to);
        } catch (InvalidArgumentException $e) {
            $windowError = $e->getMessage();
        }

        $pairs = FxPair::query()->orderBy('pair_key')->get();
        $pairKey = $this->pairFilter($pairs);

        // Newest quoted date first, id as the tiebreaker: with a pair chosen this exact order is served by
        // fx_rate_scopes_pair_date_unique (fx_pair_id, rate_date), so the page reads ~25 index rows instead of
        // walking the table backwards by id and discarding everything outside the window.
        $query = FxRateScope::query()->orderByDesc('rate_date')->orderByDesc('id');

        if ($window === null) {
            $query->whereRaw('1 = 0'); // an invalid window lists nothing — never "everything"
        } else {
            $query->where('rate_date', '>=', $window[0]->format('Y-m-d'))->where('rate_date', '<=', $window[1]->format('Y-m-d'));
        }

        if ($pairKey !== null) {
            $query->where('fx_pair_id', $pairs->firstWhere('pair_key', $pairKey)->id); // served by fx_rate_scopes_pair_date_unique
        }

        $scopes = $query->paginate(self::PER_PAGE);

        // Two grouped lookups for the whole page — never one query per row.
        $current = FxRate::query()->whereIn('id', $scopes->pluck('current_rate_id')->filter()->all())->get()->keyBy('id');
        $revisions = FxRate::query()->selectRaw('scope_id, COUNT(*) AS revisions')->whereIn('scope_id', $scopes->pluck('id')->all())->groupBy('scope_id')->get()->keyBy('scope_id');

        return view('livewire.dashboard.finance.fx-rates', [
            'scopes' => $scopes,
            'pairs' => $pairs,
            'pairsById' => $pairs->keyBy('id'),
            'current' => $current,
            'revisions' => $revisions,
            'pairKey' => $pairKey,
            'windowError' => $windowError,
            'maxDays' => self::MAX_DAYS,
            'canAudit' => (bool) $user->can(Permission::AuditView->value),
            'auditUrl' => route('dashboard.audit', ['action' => 'fx.rate_recorded']),
        ]);
    }

    /** @param  Collection<int, FxPair>  $pairs */
    private function pairFilter($pairs): ?string
    {
        $key = strtoupper(trim($this->pair));

        return $key !== '' && $pairs->firstWhere('pair_key', $key) !== null ? $key : null;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} inclusive UTC day window, ≤ MAX_DAYS
     */
    public static function window(string $from, string $to): array
    {
        $parse = static function (string $value): CarbonImmutable {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value), 'UTC');
            } catch (\Throwable) {
                $date = false;
            }

            if ($date === false) {
                throw new InvalidArgumentException('صيغة التاريخ غير صالحة (YYYY-MM-DD، UTC).');
            }

            return $date;
        };

        $start = $parse($from);
        $end = $parse($to);

        if ($end < $start) {
            throw new InvalidArgumentException('نهاية النافذة يجب أن تكون بعد بدايتها.');
        }

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('النافذة الأقصى '.self::MAX_DAYS.' يومًا.');
        }

        return [$start, $end];
    }

    protected function refreshRecord(): void
    {
        // The list re-reads itself on every render; nothing is held across a stale refusal.
    }
}
