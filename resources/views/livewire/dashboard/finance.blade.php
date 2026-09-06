@php
    use App\Data\Finance\GrossMarginStatus;
    use App\Data\Finance\MrrHistoryDay;
    use App\Enums\CoverageStatus;
    use App\Services\Finance\FinanceQuery;
@endphp
<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">المالية</h1>
            <p class="mt-1 text-sm text-slate-500">أرقام <strong>محسوبة (Calculated)</strong> داخليًا — ليست إيرادًا محصَّلًا ولا ربحية نهائية. التجميع اليومي/الشهري بتوقيت <span dir="ltr">UTC</span>. المصطلحات Collected / Actual / Reconciled تبدأ في Phase E.</p>
        </div>
        @if ($canExport && $exportUrl)
            <a href="{{ $exportUrl }}" class="rounded-lg border border-emerald-600 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50">تصدير CSV (Calculated)</a>
        @endif
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="finance-disclaimer">
        Calculated, not collected · timezone <span dir="ltr">UTC</span> · Historical revenue: <strong>NOT AVAILABLE</strong> · Gross margin: <strong>NOT AVAILABLE — Phase E</strong>
    </div>

    {{-- ─── 1. Current subscription run-rate — as of now ─────────────────── --}}
    <section class="mb-8" data-testid="section-current">
        <h2 class="text-lg font-bold text-slate-800">معدّل الاشتراكات الحالي (Run-rate) — كما في الآن</h2>
        <p class="mb-3 text-xs text-slate-500">Current Subscription Run-rate — as of <span dir="ltr">{{ $current->asOf->toIso8601String() }}</span> · calculation v{{ $current->calculationVersion }} · سعر القائمة الشهري المكافئ × الاشتراكات النشطة غير المنتهية. لا يتأثر بنطاق التاريخ أدناه ولا يمثّل إيرادًا لأي فترة.</p>

        @if ($currentByCurrency === [])
            <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500">لا توجد اشتراكات نشطة بباقة مسعَّرة الآن.</div>
        @else
            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($currentByCurrency as $currency => $kpi)
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="current-{{ $currency }}">
                        <p class="text-xs font-semibold text-slate-500" dir="ltr">{{ $currency }}</p>
                        <div class="mt-2 grid grid-cols-3 gap-2">
                            <div><p class="text-[11px] text-slate-500">Current Calculated MRR</p><p class="text-xl font-bold text-slate-800" dir="ltr">{{ $kpi['mrr'] }}</p></div>
                            <div><p class="text-[11px] text-slate-500">Current Calculated ARR</p><p class="text-xl font-bold text-slate-800" dir="ltr">{{ $kpi['arr'] }}</p></div>
                            <div><p class="text-[11px] text-slate-500">Current Calculated ARPU</p><p class="text-xl font-bold text-slate-800" dir="ltr">{{ $kpi['arpu'] ?? 'N/A' }}</p></div>
                        </div>
                        <p class="mt-2 text-xs text-slate-600" dir="ltr">active {{ $kpi['active'] }} · trialing {{ $kpi['trialing'] }} (لا تدخل MRR)</p>
                        <p class="text-xs text-amber-800" dir="ltr">Subscriptions with past_due status: {{ $kpi['past_due'] }} <span dir="rtl">(اشتراكات بحالة Past Due — لا تدخل MRR؛ لا توجد بيانات تحصيل)</span></p>
                    </div>
                @endforeach
            </div>
        @endif
        <p class="mt-2 text-xs text-slate-500" dir="ltr">No plan (not a currency, never revenue): active {{ $unassigned['active'] }} · trialing {{ $unassigned['trialing'] }} · past_due {{ $unassigned['past_due'] }}</p>
        <p class="text-[11px] text-slate-400">العملات لا تُجمع مع بعضها (لا FX).</p>
    </section>

    {{-- ─── 2. Usage & cost analysis — selected UTC window ───────────────── --}}
    <section class="mb-8" data-testid="section-window">
        <h2 class="text-lg font-bold text-slate-800">تحليل الاستخدام والتكلفة — النافذة المحددة (UTC)</h2>
        <p class="mb-3 text-xs text-slate-500">Usage &amp; Cost Analysis — selected UTC window (حتى {{ $maxDays }} يومًا). التكلفة المعروفة تُجمع من الصفوف المسعَّرة فقط؛ غير المسعَّر يُعدّ ولا يُجمع.</p>

        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <label class="block text-sm"><span class="text-slate-600">من تاريخ (UTC)</span>
                <input type="date" wire:model.live="from" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">إلى تاريخ (UTC، شامل)</span>
                <input type="date" wire:model.live="to" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">الباقة (snapshot على الحدث)</span>
                <select wire:model.live="plan_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm" dir="ltr">
                    <option value="">الكل</option>
                    <option value="{{ FinanceQuery::PLAN_NONE }}">بلا باقة</option>
                    @foreach ($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->slug }}</option>@endforeach
                </select></label>
            <label class="block text-sm"><span class="text-slate-600">المزوّد</span>
                <select wire:model.live="provider" class="mt-1 w-full rounded-lg border-slate-300 text-sm" dir="ltr">
                    <option value="">الكل</option>
                    @foreach ($providers as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                </select></label>
            <label class="block text-sm"><span class="text-slate-600">النموذج</span>
                <input type="text" wire:model.live.debounce.400ms="model" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">العملية</span>
                <input type="text" wire:model.live.debounce.400ms="operation" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">القناة</span>
                <input type="text" wire:model.live.debounce.400ms="channel" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">التكلفة</span>
                <select wire:model.live="cost" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="">الكل</option>
                    <option value="priced">مسعّرة (تكلفة معروفة)</option>
                    <option value="unpriced">غير مسعّرة (تكلفة غير معروفة)</option>
                </select></label>
            <label class="block text-sm"><span class="text-slate-600">الإسناد</span>
                <select wire:model.live="attribution" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="">الكل</option>
                    <option value="subscriber">مشتركون</option>
                    <option value="system">النظام (غير منسوب)</option>
                </select></label>
            <label class="block text-sm"><span class="text-slate-600">اتجاه التكلفة</span>
                <select wire:model.live="granularity" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="day">يومي (UTC)</option>
                    <option value="month">شهري (UTC)</option>
                </select></label>
            <label class="block text-sm"><span class="text-slate-600">أعلى N مشتركين</span>
                <input type="number" min="1" max="{{ FinanceQuery::TOP_LIMIT_MAX }}" wire:model.live.debounce.400ms="top" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        </div>

        @if ($error)
            <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
        @endif

        @if ($window)
            @php($totals = $window['totals'])
            @php($coverage = $window['coverage'])
            @php($margin = $window['margin'])

            <p class="mb-2 text-xs text-slate-500" dir="ltr">Window (UTC): {{ $window['from'] }} → {{ $window['to'] }} · cost currency {{ $totals->currency }}</p>

            <div class="mb-4 grid gap-3 md:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs text-slate-500">Known Provider Cost</p>
                    <p class="mt-1 text-2xl font-bold text-emerald-700" dir="ltr">{{ $totals->knownProviderCost }} {{ $totals->currency }}</p>
                    <p class="text-[11px] text-slate-400" dir="ltr">{{ number_format($totals->pricedRows) }} priced rows of {{ number_format($totals->rows) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs text-slate-500">Known Communication Cost</p>
                    @if ($coverage->communication === CoverageStatus::Complete)
                        <p class="mt-1 text-2xl font-bold text-emerald-700" dir="ltr">{{ $totals->knownCommunicationCost }} {{ $totals->currency }}</p>
                    @else
                        <p class="mt-1 text-lg font-bold text-amber-800" dir="ltr">{{ $coverage->communication === CoverageStatus::Incomplete ? 'COVERAGE INCOMPLETE' : 'NO PRODUCER' }}</p>
                        <p class="text-[11px] text-amber-800">لا يوجد مسجِّل لتكلفة التواصل — ليست صفرًا</p>
                    @endif
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs text-slate-500">Known External Cost</p>
                    @if ($coverage->external === CoverageStatus::Complete)
                        <p class="mt-1 text-2xl font-bold text-emerald-700" dir="ltr">{{ $totals->knownExternalCost }} {{ $totals->currency }}</p>
                    @else
                        <p class="mt-1 text-lg font-bold text-amber-800" dir="ltr">NO PRODUCER</p>
                        <p class="text-[11px] text-amber-800">لا مصدر للتكاليف الخارجية بعد — ليست صفرًا</p>
                    @endif
                </div>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
                    <p class="text-xs text-amber-800">Unpriced / Unknown (لا تُجمع)</p>
                    <p class="mt-1 text-2xl font-bold text-amber-800" dir="ltr">{{ number_format($totals->unpricedRows) }}</p>
                    <p class="text-[11px] text-amber-900" dir="ltr">tokens in {{ number_format($totals->unpricedInputUnits) }} / out {{ number_format($totals->unpricedOutputUnits) }}</p>
                    @if ($totals->unpricedByReason !== [])
                        <ul class="mt-1 space-y-0.5 text-[11px] text-amber-900">
                            @foreach ($totals->unpricedByReason as $reason => $n)
                                <li><span dir="ltr">{{ number_format($n) }}</span> — {{ FinanceQuery::reasonLabel($reason) }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="mb-4 grid gap-3 md:grid-cols-2">
                <div class="rounded-2xl border border-amber-300 bg-white p-4 shadow-sm" data-testid="coverage">
                    <p class="text-xs font-semibold text-slate-700">Cost coverage</p>
                    <ul class="mt-1 space-y-0.5 text-xs" dir="ltr">
                        <li>provider: <strong>{{ $coverage->provider->value }}</strong> ({{ $coverage->providerUnpricedRows }} unpriced)</li>
                        <li>communication: <strong>{{ $coverage->communication->value }}</strong> ({{ $coverage->communicationUncoveredRows }} rows WhatsApp/unknown channel)</li>
                        <li>external: <strong>{{ $coverage->external->value }}</strong></li>
                    </ul>
                    @if ($coverage->warnings() !== [])
                        <ul class="mt-2 space-y-1">
                            @foreach ($coverage->warnings() as $warning)
                                <li class="rounded-lg bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900" dir="ltr">{{ $warning }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="mt-2 text-[11px] text-slate-500">Known cost = full service cost: <strong dir="ltr">{{ $coverage->knownCostIsFullServiceCost() ? 'yes' : 'NO' }}</strong></p>
                    <p class="text-[11px] text-slate-500" dir="ltr">System-attributed rows in window: {{ number_format($totals->systemRows) }} (shown apart in the plan breakdown)</p>
                </div>
                <div class="rounded-2xl border border-slate-300 bg-slate-50 p-4 shadow-sm" data-testid="gross-margin">
                    <p class="text-xs font-semibold text-slate-700">Gross Margin</p>
                    <p class="mt-1 text-xl font-bold text-slate-800" dir="ltr">{{ $margin->label() }}</p>
                    <ul class="mt-2 space-y-0.5 text-xs text-slate-600">
                        @foreach ($margin->reasons as $reason)
                            <li>• {{ GrossMarginStatus::reasonLabel($reason) }} <span class="text-slate-400" dir="ltr">({{ $reason }})</span></li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-[11px] text-slate-500">لا يُحسب أي ربح إجمالي جزئي أو تقديري في Phase D؛ لقطات MRR هي run-rate لا إيراد مكتسب.</p>
                </div>
            </div>

            <div class="mb-4 grid gap-3 lg:grid-cols-2">
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <p class="px-4 pt-3 text-xs font-semibold text-slate-700">لكل باقة (snapshot على الحدث) — النظام وبلا باقة منفصلان</p>
                    @include('livewire.dashboard._finance_bucket_table', ['buckets' => $window['byPlan'], 'currency' => $totals->currency, 'label' => fn ($b) => $b->dimensions['attribution'] === 'system' ? 'تكلفة النظام غير المنسوبة' : ($b->dimensions['plan_slug'] ?? ($b->dimensions['plan_id'] === null ? 'بلا باقة' : 'plan:'.$b->dimensions['plan_id'])), 'testid' => 'by-plan'])
                </div>
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <p class="px-4 pt-3 text-xs font-semibold text-slate-700">لكل مزوّد / نموذج</p>
                    @include('livewire.dashboard._finance_bucket_table', ['buckets' => $window['byProviderModel'], 'currency' => $totals->currency, 'label' => fn ($b) => ($b->dimensions['provider'] ?? '—').':'.($b->dimensions['model'] ?? '—'), 'testid' => 'by-provider-model'])
                </div>
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <p class="px-4 pt-3 text-xs font-semibold text-slate-700">لكل عملية / قناة</p>
                    @include('livewire.dashboard._finance_bucket_table', ['buckets' => $window['byOperationChannel'], 'currency' => $totals->currency, 'label' => fn ($b) => ($b->dimensions['operation'] ?? 'unknown').' / '.($b->dimensions['channel'] ?? 'unknown'), 'testid' => 'by-operation-channel'])
                </div>
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-testid="top-subscribers">
                    <p class="px-4 pt-3 text-xs font-semibold text-slate-700">أعلى {{ $top }} مشتركين تكلفة معروفة (بلا صفوف النظام؛ معرّف داخلي فقط)</p>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[420px] text-right text-sm">
                            <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 font-medium">المشترك</th><th class="px-3 py-2 font-medium">الصفوف</th><th class="px-3 py-2 font-medium">غير مسعَّر</th><th class="px-3 py-2 font-medium">التكلفة المعروفة</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($window['topSubscribers'] as $bucket)
                                    <tr wire:key="top-{{ $bucket->dimensions['subscriber_id'] }}">
                                        <td class="px-3 py-2 font-mono text-xs" dir="ltr">
                                            @if ($canViewSubscribers)
                                                <a class="text-emerald-700 hover:underline" href="{{ route('dashboard.subscribers.show', $bucket->dimensions['subscriber_id']) }}">#{{ $bucket->dimensions['subscriber_id'] }}</a>
                                            @else
                                                #{{ $bucket->dimensions['subscriber_id'] }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $bucket->rows }}</td>
                                        <td class="px-3 py-2 font-mono text-xs text-amber-800" dir="ltr">{{ $bucket->unpricedRows }}</td>
                                        <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $bucket->knownCost }} {{ $totals->currency }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400">لا مشتركين في هذا النطاق.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="cost-trend">
                <p class="text-xs font-semibold text-slate-700">اتجاه التكلفة المعروفة — {{ $granularity === 'month' ? 'شهري' : 'يومي' }} (UTC)</p>
                <p class="text-[11px] text-slate-400">Known cost trend (UTC {{ $granularity }} buckets); unpriced rows shown per bucket, never as money.</p>
                <ul class="mt-2 space-y-1">
                    @forelse ($window['trend'] as $bucket)
                        <li class="flex items-center gap-2 text-xs" wire:key="trend-{{ $bucket->dimensions['bucket'] }}">
                            <span class="w-20 shrink-0 font-mono text-slate-500" dir="ltr">{{ $bucket->dimensions['bucket'] }}</span>
                            <span class="h-3 flex-1 rounded bg-slate-100"><span class="block h-3 rounded bg-emerald-500" style="width: {{ $window['trendBars'][$bucket->dimensions['bucket']] ?? 0 }}%"></span></span>
                            <span class="w-40 shrink-0 font-mono" dir="ltr">{{ $bucket->knownCost }} {{ $totals->currency }}</span>
                            <span class="w-24 shrink-0 font-mono text-amber-800" dir="ltr">{{ $bucket->unpricedRows }} unpriced</span>
                        </li>
                    @empty
                        <li class="text-xs text-slate-400">لا أحداث في هذا النطاق.</li>
                    @endforelse
                </ul>
            </div>
        @endif
    </section>

    {{-- ─── 3. MRR snapshot history — run-rate, not revenue ──────────────── --}}
    @if ($window)
        @php($series = $window['history'])
        <section class="mb-8" data-testid="section-history">
            <h2 class="text-lg font-bold text-slate-800">تاريخ لقطات MRR (Run-rate)</h2>
            <p class="mb-3 text-xs text-slate-500">MRR Snapshot History — Historical MRR Run-rate as frozen by <code dir="ltr">sanad:finance:snapshot</code> each UTC day. <strong>ليس إيرادًا</strong>: لا يُجمع عبر الأيام ولا يُضرب بعدد الأيام ولا يُقارن بتكلفة الاستخدام. لا interpolation ولا backfill.</p>
            @php($counts = $series->counts())
            <p class="mb-2 text-xs text-slate-500" dir="ltr">first snapshot: {{ $series->firstSnapshotDate ?? 'none' }} · captured {{ $counts['captured'] }} · NOT CAPTURED {{ $counts['not_captured'] }} · NOT AVAILABLE {{ $counts['not_available'] }}</p>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <ul class="space-y-1">
                    @foreach ($series->days as $day)
                        <li class="flex items-center gap-2 text-xs" wire:key="hist-{{ $day->date }}">
                            <span class="w-20 shrink-0 font-mono text-slate-500" dir="ltr">{{ $day->date }}</span>
                            @if ($day->status === MrrHistoryDay::NOT_AVAILABLE)
                                <span class="font-mono text-slate-400" dir="ltr">NOT AVAILABLE</span>
                            @elseif ($day->status === MrrHistoryDay::NOT_CAPTURED)
                                <span class="font-mono text-amber-700" dir="ltr">NOT CAPTURED</span>
                            @else
                                <span class="flex flex-1 flex-col gap-0.5">
                                    @forelse ($day->byCurrency as $currency => $entry)
                                        <span class="flex items-center gap-2">
                                            <span class="w-10 shrink-0 font-mono text-slate-500" dir="ltr">{{ $currency }}</span>
                                            <span class="h-3 flex-1 rounded bg-slate-100"><span class="block h-3 rounded bg-sky-500" style="width: {{ $window['historyBars'][$day->date][$currency] ?? 0 }}%"></span></span>
                                            <span class="w-40 shrink-0 font-mono" dir="ltr">MRR {{ $entry['mrr'] }}</span>
                                            <span class="w-40 shrink-0 font-mono text-slate-500" dir="ltr">active {{ $entry['active'] }} · past_due {{ $entry['past_due'] }}</span>
                                        </span>
                                    @empty
                                        <span class="font-mono text-slate-400" dir="ltr">captured — no subscriptions</span>
                                    @endforelse
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif
</div>
