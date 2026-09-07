<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">أسعار الصرف اليدوية (FX Rates)</h1>
            <p class="mt-1 text-sm text-slate-500">سعر واحد لكل (زوج، تاريخ) بالاتجاه الرسمي، وكل تصحيح مراجعة جديدة تُلحق ولا تُعدَّل. لا يوجد «أحدث سعر» ولا «أقرب سعر»: التحويل يسمّي مراجعته صراحةً. تُقرأ القائمة عبر زوج واحد فقط (اختر الزوج أولًا). التوقيت <span dir="ltr">UTC</span>.</p>
        </div>
        <nav class="flex flex-wrap gap-2">
            <a href="{{ route('dashboard.finance.fx') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-fx">الأزواج وعملة التقرير</a>
            <a href="{{ route('dashboard.finance.fx.conversions') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-conversions">التحويلات</a>
            @if ($canAudit)
                <a href="{{ $auditUrl }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-audit-rates">سجل تسجيل الأسعار</a>
            @endif
        </nav>
    </header>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    {{-- ─── Filters (URL, allowlisted, bounded) ──────────────────────────── --}}
    <section class="mb-4" data-testid="section-filters">
        <div class="grid gap-3 md:grid-cols-3">
            <label class="block text-sm"><span class="text-slate-600">الزوج</span>
                <select wire:model.live="pair" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-pair"><option value="">الكل</option>@foreach ($pairs as $p)<option value="{{ $p->pair_key }}">{{ $p->pair_key }} (1 {{ $p->base_currency }} = rate × {{ $p->quote_currency }})</option>@endforeach</select></label>
            <label class="block text-sm"><span class="text-slate-600">من تاريخ (UTC)</span><input type="date" wire:model.live="from" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-from"></label>
            <label class="block text-sm"><span class="text-slate-600">إلى تاريخ (شامل)</span><input type="date" wire:model.live="to" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-to"></label>
        </div>
        @if ($windowError)
            <p class="mt-2 text-sm text-rose-700" data-testid="window-error">{{ $windowError }}</p>
        @else
            <p class="mt-2 text-xs text-slate-500">نافذة تاريخ السعر، حتى {{ $maxDays }} يومًا.</p>
        @endif
    </section>

    {{-- ─── Quote scopes (one row per pair+date) — a pair is required ────── --}}
    <section class="mb-8" data-testid="section-rates">
        @if ($scopes === null)
            <div class="rounded-2xl border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-600" data-testid="rates-select-pair">
                <p class="font-semibold text-slate-800" dir="ltr">Select a currency pair</p>
                <p class="mt-1">تُقرأ الأسعار عبر زوج واحد فقط: اختر الزوج أعلاه لعرض تواريخه المسعَّرة. بلا زوج لا يُستعلم عن أي سعر ولا تُرقَّم أي صفحة.</p>
            </div>
        @else
            <h2 class="text-base font-bold text-slate-800">النطاقات — <span dir="ltr">{{ $pairKey }}</span> · {{ $scopes->total() }} rows · page {{ $scopes->currentPage() }} of {{ max(1, $scopes->lastPage()) }}</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                        <th class="px-3 py-2 text-left">Scope</th><th class="px-3 py-2 text-left">Pair (official)</th><th class="px-3 py-2 text-left">Rate date (UTC)</th><th class="px-3 py-2 text-left">Current revision</th><th class="px-3 py-2 text-right">Rate</th><th class="px-3 py-2 text-right">Revisions</th><th class="px-3 py-2 text-left">Evidence</th><th class="px-3 py-2 text-left">Detail</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($scopes as $scope)
                        @php($rate = $scope->current_rate_id ? ($current[$scope->current_rate_id] ?? null) : null)
                        @php($pair = $pairsById[$scope->fx_pair_id] ?? null)
                        <tr class="border-t border-slate-100" data-testid="scope-{{ $scope->id }}">
                            <td class="px-3 py-2">{{ $scope->id }}</td>
                            <td class="px-3 py-2">{{ $pair?->pair_key ?? '—' }}@if ($pair) <span class="text-xs text-slate-500">1 {{ $pair->base_currency }} = rate × {{ $pair->quote_currency }}</span>@endif</td>
                            <td class="px-3 py-2">{{ $scope->rate_date->format('Y-m-d') }}</td>
                            <td class="px-3 py-2">{{ $scope->current_rate_id ? '#'.$scope->current_rate_id : 'NO QUOTE' }}</td>
                            <td class="px-3 py-2 text-right">{{ $rate?->rate ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">{{ $revisions[$scope->id]->revisions ?? 0 }}</td>
                            <td class="px-3 py-2 text-xs">{{ $rate?->evidence_ref ?? '—' }}</td>
                            <td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.fx.rates.show', $scope->id) }}">detail</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-3 text-center text-slate-500">لا أسعار لهذا الزوج في النافذة.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-2">{{ $scopes->links() }}</div>
        @endif
    </section>

    {{-- ─── Record a first quote for a date ──────────────────────────────── --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-rate">
        <h2 class="text-base font-bold text-slate-800">تسجيل سعر لتاريخ (Record Rate for Date)</h2>
        <p class="mb-3 text-xs text-slate-500">للتاريخ الذي لا سعر له بعد (المراجعة الحالية المتوقعة = <span dir="ltr">none</span>). إن كان للتاريخ سعر بالفعل يُرفض الطلب كـ<span dir="ltr">STATE CHANGED</span> ولا يُكتب شيء — التصحيح يتم من صفحة النطاق حيث تظهر المراجعة التي ستُستبدل. أدخل السعر بالاتجاه الرسمي للزوج؛ لا يُقلب تلقائيًا.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'rate'])
        <form wire:submit="recordRate" class="grid gap-2 md:grid-cols-3">
            <label class="text-sm">مفتاح المحاولة<input type="text" wire:model="rateKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="rate-attempt-key"></label>
            <label class="text-sm">Base<input type="text" wire:model="rateBase" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="rate-base"></label>
            <label class="text-sm">Quote<input type="text" wire:model="rateQuote" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="rate-quote"></label>
            <label class="text-sm">التاريخ (UTC)<input type="date" wire:model="rateDate" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="rate-date"></label>
            <label class="text-sm">السعر (1 Base = ? Quote)<input type="text" wire:model="rateValue" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="rate-value"></label>
            <label class="text-sm">مرجع الدليل<input type="text" wire:model="rateEvidence" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="rate-evidence"></label>
            <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="rateReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <div class="md:col-span-3">
                @if ($confirming)
                    <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" dir="ltr" data-testid="rate-confirm">Freeze quote: 1 {{ strtoupper($rateBase) ?: '???' }} = {{ $rateValue ?: '?' }} {{ strtoupper($rateQuote) ?: '???' }} on {{ $rateDate }} (UTC) · expected current revision: none</p>
                    <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50" data-testid="rate-submit">تأكيد تسجيل السعر</button>
                    <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">إلغاء</button>
                @else
                    <button type="button" wire:click="openConfirm" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700" data-testid="rate-open">تسجيل السعر…</button>
                @endif
            </div>
        </form>
    </section>
</div>
