<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">أزواج الصرف وعملة التقرير (FX Pairs &amp; Reporting Currency)</h1>
            <p class="mt-1 text-sm text-slate-500">الأصول تبقى بعملتها. زوج واحد قانوني لكل عملتين باتجاه رسمي واحد؛ الزوج المعاكس مرفوض ولا يُقلب اتجاه مخزَّن أبدًا. عملة التقرير الحالية: <strong dir="ltr" data-testid="reporting-currency">{{ $reportingCurrency }}</strong>. التوقيت <span dir="ltr">UTC</span>.</p>
        </div>
        <nav class="flex flex-wrap gap-2">
            <a href="{{ route('dashboard.finance.fx.rates') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-rates">الأسعار (Rates)</a>
            <a href="{{ route('dashboard.finance.fx.conversions') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-conversions">التحويلات (Conversions)</a>
            @if ($canAudit)
                <a href="{{ $auditUrl }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-audit-currency">سجل تغيير عملة التقرير</a>
            @endif
        </nav>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="fx-disclaimer">
        Manual quotes only · one canonical pair per two currencies · 1 BASE = rate × QUOTE (direct = multiply, inverse = divide, same rate row) · every conversion names an explicit <code>fx_rate_id</code> — no latest / nearest / fallback rate · rounded once, half-up, at the target scale · Reporting view only — never changes payments, refunds or reconciliations · Revenue Recognition / Gross Profit: <strong>NOT AVAILABLE</strong>
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    {{-- ─── Pairs (canonical, official orientation) ──────────────────────── --}}
    <section class="mb-8" data-testid="section-pairs">
        <h2 class="text-base font-bold text-slate-800">الأزواج — {{ $pairs->count() }}</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                    <th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Key</th><th class="px-3 py-2 text-left">Official orientation</th><th class="px-3 py-2 text-right">Quoted dates</th><th class="px-3 py-2 text-right">Revisions</th><th class="px-3 py-2 text-left">Latest quoted date (UTC)</th><th class="px-3 py-2 text-left">Rates</th>
                </tr></thead>
                <tbody>
                @forelse ($pairs as $pair)
                    <tr class="border-t border-slate-100" data-testid="pair-{{ $pair->id }}">
                        <td class="px-3 py-2">{{ $pair->id }}</td>
                        <td class="px-3 py-2">{{ $pair->pair_key }}</td>
                        <td class="px-3 py-2">1 {{ $pair->base_currency }} = rate × {{ $pair->quote_currency }}</td>
                        <td class="px-3 py-2 text-right">{{ $scopes[$pair->id]->scopes ?? 0 }}</td>
                        <td class="px-3 py-2 text-right">{{ $revisions[$pair->id]->revisions ?? 0 }}</td>
                        <td class="px-3 py-2">{{ isset($scopes[$pair->id]) ? \Illuminate\Support\Str::substr((string) $scopes[$pair->id]->latest, 0, 10) : '—' }}</td>
                        <td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.fx.rates', ['pair' => $pair->pair_key]) }}">rates</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-3 text-center text-slate-500">لا أزواج.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- ─── Create pair ──────────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-pair">
            <h2 class="text-base font-bold text-slate-800">إنشاء زوج (Create FX Pair)</h2>
            <p class="mb-3 text-xs text-slate-500">زوج واحد لكل عملتين (<code>min:max</code>)؛ الاتجاه الذي تدخله هنا يصبح الاتجاه الرسمي لكل أسعار الزوج. الزوج المعاكس مرفوض.</p>
            @include('livewire.dashboard.finance._payment_errors', ['form' => 'pair'])
            <form wire:submit="createPair" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">مفتاح المحاولة<input type="text" wire:model="pairKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="pair-attempt-key"></label>
                <div class="hidden md:block"></div>
                <label class="text-sm">Base<input type="text" wire:model="pairBase" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="pair-base"></label>
                <label class="text-sm">Quote<input type="text" wire:model="pairQuote" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="pair-quote"></label>
                <div class="md:col-span-2">
                    @if ($confirming === 'pair')
                        <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" dir="ltr" data-testid="pair-confirm">Official orientation: 1 {{ strtoupper($pairBase) ?: '???' }} = rate × {{ strtoupper($pairQuote) ?: '???' }} — permanent, never flipped.</p>
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50" data-testid="pair-submit">تأكيد إنشاء الزوج</button>
                        <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">إلغاء</button>
                    @else
                        <button type="button" wire:click="openConfirm('pair')" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700" data-testid="pair-open">إنشاء الزوج…</button>
                    @endif
                </div>
            </form>
        </section>

        {{-- ─── Reporting currency ───────────────────────────────────────── --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-currency">
            <h2 class="text-base font-bold text-slate-800">عملة التقرير (Reporting Currency)</h2>
            <p class="mb-3 text-xs text-slate-500">تغيير عملة التقرير <strong>لا يعيد حساب أي تحويل مجمَّد</strong> ولا يكتب أي صف مالي: تتغيّر التسميات فقط (<span dir="ltr">NATIVE / CONVERTED / NOT CONVERTED</span>). الصفحة تُرسل العملة التي عرضتها (<span dir="ltr">{{ $reportingCurrency }}</span>) كعقد تزامن: إذا غيّرها طلب آخر يُرفض التغيير كـ<span dir="ltr">STATE CHANGED</span> ولا يُكتب شيء. اكتب الرمز الجديد حرفيًا للتأكيد.</p>
            @include('livewire.dashboard.finance._payment_errors', ['form' => 'currency'])
            <form wire:submit="setReportingCurrency" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">مفتاح المحاولة<input type="text" wire:model="rcKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="currency-attempt-key"></label>
                <label class="text-sm">العملة الحالية المتوقعة (مرسلة كما عُرضت)<input type="text" wire:model="rcExpected" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="currency-expected"></label>
                <label class="text-sm">العملة الجديدة<input type="text" wire:model="rcCode" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="currency-code"></label>
                <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="rcReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="currency-reason"></label>
                <label class="text-sm">نافذة المعاينة — من (UTC)<input type="date" wire:model="impactFrom" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="impact-from"></label>
                <label class="text-sm">إلى (شامل)<input type="date" wire:model="impactTo" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="impact-to"></label>
                <div class="md:col-span-2">
                    <button type="button" wire:click="previewImpact" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" data-testid="currency-preview">PREVIEW IMPACT</button>
                    @if ($confirming !== 'currency')
                        <button type="button" wire:click="openConfirm('currency')" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700" data-testid="currency-open">تغيير عملة التقرير…</button>
                    @endif
                </div>

                @if ($impact)
                    <div class="md:col-span-2 overflow-x-auto rounded-xl border border-slate-200" data-testid="currency-impact">
                        <p class="bg-slate-50 px-3 py-2 text-xs text-slate-600" dir="ltr">Informational preview of {{ $impact['code'] }} (current {{ $impact['current'] }}) over policy dates {{ $impact['from'] }} → {{ $impact['to'] }} (UTC, inclusive), computed at {{ $impact['at'] }} UTC — counts only, read-only, nothing recomputed. The services stay the authority.</p>
                        <table class="min-w-full text-sm" dir="ltr">
                            <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">Subject type</th><th class="px-3 py-2 text-right">NATIVE</th><th class="px-3 py-2 text-right">CONVERTED</th><th class="px-3 py-2 text-right">NOT CONVERTED</th></tr></thead>
                            <tbody>
                            @foreach ($impact['rows'] as $row)
                                <tr class="border-t border-slate-100" data-testid="impact-{{ $row['type'] }}">
                                    <td class="px-3 py-2">{{ $row['type'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ $row['native'] }}</td>
                                    <td class="px-3 py-2 text-right">{{ $row['converted'] }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ $row['not_converted'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($confirming === 'currency')
                    <div class="md:col-span-2 rounded-xl border border-amber-200 bg-amber-50 p-3" data-testid="currency-confirm">
                        <p class="mb-2 text-sm text-amber-900">اكتب <strong dir="ltr">{{ strtoupper(trim($rcCode)) ?: '???' }}</strong> حرفيًا لتأكيد التغيير من <span dir="ltr">{{ $rcExpected }}</span>. لا يُعاد حساب أي تحويل مجمَّد (<span dir="ltr">conversions_recomputed = 0</span>).</p>
                        <input type="text" wire:model="rcTyped" dir="ltr" maxlength="3" class="mb-2 w-full rounded-lg border-amber-300 text-sm" data-testid="currency-typed">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50" data-testid="currency-submit">تأكيد تغيير عملة التقرير</button>
                        <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">إلغاء</button>
                    </div>
                @endif
            </form>
        </section>
    </div>
</div>
