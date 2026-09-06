<div>
    <header class="mb-4">
        <h1 class="text-2xl font-bold text-slate-800">أسعار الصرف وعملة التقرير (Phase E3)</h1>
        <p class="mt-1 text-sm text-slate-500">الأصول تبقى بعملتها. كل تحويل يحمل <code>fx_rate_id</code> صريحًا لسعر يدوي بتاريخ محدد (لا أحدث سعر، لا أقرب سعر، لا احتياطي). <code>NATIVE</code> = نفس عملة التقرير بلا سعر؛ <code>NOT CONVERTED</code> = عملة مختلفة بلا تحويل مسجَّل. عملة التقرير الحالية: <strong dir="ltr">{{ $reportingCurrency }}</strong>.</p>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="fx-disclaimer">
        Manual quotes only · one canonical pair per two currencies · 1 BASE = rate × QUOTE (direct = multiply, inverse = divide, same rate row) · rounded once, half-up, at the target scale · Reporting view only — never changes payments, refunds or reconciliations · Revenue Recognition / Gross Profit: <strong>NOT AVAILABLE</strong>
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-pair">
            <h2 class="text-base font-bold text-slate-800">إنشاء زوج (Create FX Pair)</h2>
            <p class="mb-3 text-xs text-slate-500">زوج واحد لكل عملتين (`min:max`)؛ الاتجاه الذي تدخله هنا يصبح الاتجاه الرسمي لكل أسعار الزوج. الزوج المعاكس مرفوض.</p>
            @error('pair')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="pair-error">{{ $message }}</p>@enderror
            <form wire:submit="createPair" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">Base<input type="text" wire:model="pairBase" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">Quote<input type="text" wire:model="pairQuote" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">إنشاء الزوج</button></div>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-rate">
            <h2 class="text-base font-bold text-slate-800">تسجيل / تصحيح سعر لتاريخ (Record Rate for Date)</h2>
            <p class="mb-3 text-xs text-slate-500">سعر لتاريخ واحد بالاتجاه الرسمي. للتصحيح أدخل معرّف المراجعة الحالية المتوقعة؛ تغيّرها ⇒ رفض. المراجعات append-only.</p>
            @error('rate')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="rate-error">{{ $message }}</p>@enderror
            <form wire:submit="recordRate" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">Base<input type="text" wire:model="rateBase" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">Quote<input type="text" wire:model="rateQuote" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">التاريخ (UTC)<input type="date" wire:model="rateDate" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">السعر (1 Base = ? Quote)<input type="text" wire:model="rateValue" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">مرجع الدليل<input type="text" wire:model="rateEvidence" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="rateReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm md:col-span-2">المراجعة الحالية المتوقعة (فارغ = لا سعر بعد)<input type="text" wire:model="rateExpected" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">تسجيل السعر</button></div>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-conversion">
            <h2 class="text-base font-bold text-slate-800">تحويل للتقرير (Convert subject for Reporting)</h2>
            <p class="mb-3 text-xs text-slate-500">حدّد الموضوع والسعر بمعرّفه؛ الخدمة تتحقق أن السعر للزوج الصحيح وبتاريخ سياسة الموضوع (دفعة: received_at، استرداد: refunded_at، تسوية: period_end) وأنه المراجعة الحالية. لا يغيّر الموضوع.</p>
            @error('conversion')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="conversion-error">{{ $message }}</p>@enderror
            <form wire:submit="convert" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">نوع الموضوع<select wire:model="convSubjectType" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="customer_payment">customer_payment</option><option value="customer_refund">customer_refund</option><option value="cost_reconciliation">cost_reconciliation</option></select></label>
                <label class="text-sm">معرّف الموضوع<input type="text" wire:model="convSubjectId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">العملة الهدف<input type="text" wire:model="convTarget" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">fx_rate_id<input type="text" wire:model="convRateId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">التحويل الحالي المتوقع (فارغ = لا شيء)<input type="text" wire:model="convExpected" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="convReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700">تحويل</button></div>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-reporting-currency">
            <h2 class="text-base font-bold text-slate-800">عملة التقرير (Set Reporting Currency)</h2>
            <p class="mb-3 text-xs text-slate-500">الافتراضي عملة التكلفة. اكتب رمز العملة الجديد حرفيًا للتأكيد. لا يعيد حساب أي تحويل مجمَّد.</p>
            @error('reporting_currency')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="reporting-currency-error">{{ $message }}</p>@enderror
            <form wire:submit="setReportingCurrency" class="grid gap-2 md:grid-cols-3">
                <label class="text-sm">العملة الجديدة<input type="text" wire:model="rcCode" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">اكتب الرمز للتأكيد<input type="text" wire:model="rcTyped" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="rcReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-3"><button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">تغيير عملة التقرير</button></div>
            </form>
        </section>
    </div>

    {{-- ─── Reporting view: cash ─────────────────────────────────────────── --}}
    <section class="mt-8" data-testid="section-cash">
        <h2 class="text-lg font-bold text-slate-800">النقد بعملة التقرير <span dir="ltr">({{ $reportingCurrency }})</span> — نافذة UTC</h2>
        <div class="mb-3 grid gap-3 md:grid-cols-4">
            <label class="block text-sm"><span class="text-slate-600">من (UTC)</span><input type="date" wire:model.live="from" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">إلى (UTC، شامل)</span><input type="date" wire:model.live="to" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        </div>
        @if ($cashError)
            <p class="text-sm text-rose-700" data-testid="cash-error">{{ $cashError }}</p>
        @else
            <div class="mb-3 grid gap-3 md:grid-cols-3">
                @foreach ($cash['totals'] as $key => $total)
                    <div class="rounded-2xl border border-slate-200 bg-white p-4" data-testid="cash-total-{{ $key }}">
                        <p class="text-xs text-slate-500">{{ $total->label }} ({{ $total->targetCurrency }})</p>
                        <p class="text-xl font-bold text-slate-800" dir="ltr">{{ $total->amount ?? 'INCOMPLETE / NOT AVAILABLE' }}</p>
                        <p class="text-[11px] text-slate-500" dir="ltr">{{ $total->lines }} lines · {{ $total->native }} native · {{ $total->converted }} converted · {{ $total->notConverted }} not converted · {{ $total->status() }}</p>
                    </div>
                @endforeach
            </div>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">Subject</th><th class="px-3 py-2 text-left">Policy date</th><th class="px-3 py-2 text-right">Original</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-right">In {{ $reportingCurrency }}</th><th class="px-3 py-2 text-left">Rate</th></tr></thead>
                    <tbody>
                    @forelse ($cash['lines'] as $line)
                        <tr class="border-t border-slate-100" data-testid="cash-line-{{ $line->subjectType }}-{{ $line->subjectId }}">
                            <td class="px-3 py-2">{{ $line->subjectType }} #{{ $line->subjectId }}</td><td class="px-3 py-2">{{ $line->subjectDate }}</td>
                            <td class="px-3 py-2 text-right">{{ $line->sourceAmount }} {{ $line->sourceCurrency }}</td><td class="px-3 py-2 font-semibold">{{ $line->status }}</td>
                            <td class="px-3 py-2 text-right">{{ $line->reportingAmount() ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $line->fxRateId ? '#'.$line->fxRateId.' · '.$line->fxRateDate.' · '.$line->rateSnapshot.' · '.$line->direction : ($line->status === 'NATIVE' ? 'no rate (native)' : '—') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-3 text-center text-slate-500">لا مدفوعات ولا استردادات في النافذة.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ─── Reporting view: cost ─────────────────────────────────────────── --}}
    <section class="mt-8" data-testid="section-cost">
        <h2 class="text-lg font-bold text-slate-800">التكلفة المسوّاة بعملة التقرير <span dir="ltr">({{ $reportingCurrency }})</span> — أشهر UTC</h2>
        <div class="mb-3 grid gap-3 md:grid-cols-4">
            <label class="block text-sm"><span class="text-slate-600">من شهر</span><input type="month" wire:model.live="fromMonth" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">إلى شهر</span><input type="month" wire:model.live="toMonth" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        </div>
        @if ($costError)
            <p class="text-sm text-rose-700" data-testid="cost-error">{{ $costError }}</p>
        @else
            <div class="mb-3 rounded-2xl border border-slate-200 bg-white p-4" data-testid="cost-total-base">
                <p class="text-xs text-slate-500">{{ $cost['totals']['base']->label }} ({{ $reportingCurrency }}) — Base Reconciled Amounts only; adjustments stay in their native currency</p>
                <p class="text-xl font-bold text-slate-800" dir="ltr">{{ $cost['totals']['base']->amount ?? 'INCOMPLETE / NOT AVAILABLE' }}</p>
                <p class="text-[11px] text-slate-500" dir="ltr">{{ $cost['totals']['base']->lines }} lines · {{ $cost['totals']['base']->native }} native · {{ $cost['totals']['base']->converted }} converted · {{ $cost['totals']['base']->notConverted }} not converted</p>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">Reconciliation</th><th class="px-3 py-2 text-left">Policy date</th><th class="px-3 py-2 text-right">Base (native)</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-right">In {{ $reportingCurrency }}</th><th class="px-3 py-2 text-left">Rate</th></tr></thead>
                    <tbody>
                    @forelse ($cost['lines'] as $line)
                        <tr class="border-t border-slate-100" data-testid="cost-line-{{ $line->subjectId }}">
                            <td class="px-3 py-2">#{{ $line->subjectId }}</td><td class="px-3 py-2">{{ $line->subjectDate }}</td>
                            <td class="px-3 py-2 text-right">{{ $line->sourceAmount }} {{ $line->sourceCurrency }}</td><td class="px-3 py-2 font-semibold">{{ $line->status }}</td>
                            <td class="px-3 py-2 text-right">{{ $line->reportingAmount() ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $line->fxRateId ? '#'.$line->fxRateId.' · '.$line->fxRateDate.' · '.$line->rateSnapshot.' · '.$line->direction : ($line->status === 'NATIVE' ? 'no rate (native)' : '—') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-3 text-center text-slate-500">لا تسويات في الأشهر المحددة.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section data-testid="section-pairs">
            <h2 class="text-base font-bold text-slate-800">الأزواج</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Key</th><th class="px-3 py-2 text-left">Official orientation</th></tr></thead>
                    <tbody>
                    @forelse ($pairs as $pair)
                        <tr class="border-t border-slate-100"><td class="px-3 py-2">{{ $pair->id }}</td><td class="px-3 py-2">{{ $pair->pair_key }}</td><td class="px-3 py-2">1 {{ $pair->base_currency }} = rate × {{ $pair->quote_currency }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-3 py-3 text-center text-slate-500">لا أزواج.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section data-testid="section-rates">
            <h2 class="text-base font-bold text-slate-800">آخر الأسعار (المراجعات)</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Pair</th><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-right">Rate</th><th class="px-3 py-2 text-left">Supersedes</th><th class="px-3 py-2 text-left">Evidence</th></tr></thead>
                    <tbody>
                    @forelse ($rates as $rate)
                        <tr class="border-t border-slate-100" data-testid="rate-{{ $rate->id }}"><td class="px-3 py-2">{{ $rate->id }}</td><td class="px-3 py-2">{{ $rate->base_currency }}/{{ $rate->quote_currency }}</td><td class="px-3 py-2">{{ $rate->rateDate() }}</td><td class="px-3 py-2 text-right">{{ $rate->rate }}</td><td class="px-3 py-2">{{ $rate->supersedes_id ?? '—' }}</td><td class="px-3 py-2 text-xs">{{ $rate->evidence_ref }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-3 text-center text-slate-500">لا أسعار.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
