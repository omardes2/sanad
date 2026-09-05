<div>
    <header class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">الاستخدام</h1>
            <p class="mt-1 text-sm text-slate-500">دفتر الاستخدام كما سُجِّل: الصفوف المسعّرة تُجمع، وغير المسعّرة تُعدّ فقط — الصفر ليس «مجانًا». النطاق مطلوب (حتى {{ $maxDays }} يومًا).</p>
        </div>
        @if ($canExport && $exportUrl)
            <a href="{{ $exportUrl }}" class="rounded-lg border border-emerald-600 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50">تصدير CSV للنطاق الحالي</a>
        @endif
    </header>

    <div class="mb-4 grid gap-3 md:grid-cols-4">
        <label class="block text-sm"><span class="text-slate-600">من تاريخ</span>
            <input type="date" wire:model.live="from" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        <label class="block text-sm"><span class="text-slate-600">إلى تاريخ (شامل)</span>
            <input type="date" wire:model.live="to" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        <label class="block text-sm"><span class="text-slate-600">المزوّد</span>
            <select wire:model.live="provider" class="mt-1 w-full rounded-lg border-slate-300 text-sm" dir="ltr">
                <option value="">الكل</option>
                @foreach ($providers as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
            </select></label>
        <label class="block text-sm"><span class="text-slate-600">النموذج</span>
            <input type="text" wire:model.live.debounce.400ms="model" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        <label class="block text-sm"><span class="text-slate-600">معرّف المشترك الداخلي</span>
            <input type="text" wire:model.live.debounce.400ms="subscriber_id" dir="ltr" inputmode="numeric" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        <label class="block text-sm"><span class="text-slate-600">النتيجة</span>
            <select wire:model.live="outcome" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                <option value="">الكل</option>
                <option value="succeeded">نجحت</option>
                <option value="downstream_failed">فشل لاحق</option>
            </select></label>
        <label class="block text-sm"><span class="text-slate-600">العملية</span>
            <input type="text" wire:model.live.debounce.400ms="operation" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        <label class="block text-sm"><span class="text-slate-600">التكلفة</span>
            <select wire:model.live="cost" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                <option value="">الكل</option>
                <option value="priced">مسعّرة (تكلفة معروفة)</option>
                <option value="unpriced">غير مسعّرة (تكلفة غير معروفة)</option>
            </select></label>
    </div>

    @if ($error)
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    @if ($totals)
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">الصفوف في النطاق</p>
                <p class="mt-1 text-2xl font-bold text-slate-800" dir="ltr">{{ number_format($totals['rows']) }}</p>
                <p class="text-[11px] text-slate-400" dir="ltr">in {{ number_format($totals['input_units']) }} / out {{ number_format($totals['output_units']) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">مسعّرة (تكلفة معروفة)</p>
                <p class="mt-1 text-2xl font-bold text-emerald-700" dir="ltr">{{ number_format($totals['priced_rows']) }}</p>
                @if ($showCosts)
                    <p class="text-sm font-medium text-slate-700" dir="ltr">{{ $totals['priced_total'] }} {{ $totals['currency'] }}</p>
                @endif
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm md:col-span-2">
                <p class="text-xs text-amber-800">غير مسعّرة — تكلفة غير معروفة (لا تُجمع)</p>
                <p class="mt-1 text-2xl font-bold text-amber-800" dir="ltr">{{ number_format($totals['unpriced_rows']) }}</p>
                @if ($totals['unpriced_by_reason'] !== [])
                    <ul class="mt-1 space-y-0.5 text-[11px] text-amber-900">
                        @foreach ($totals['unpriced_by_reason'] as $reason => $n)
                            <li><span dir="ltr">{{ number_format($n) }}</span> — {{ \App\Services\Usage\UsageQuery::reasonLabel($reason) }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-right text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-3 font-medium">الوقت</th>
                        <th class="px-3 py-3 font-medium">المشترك</th>
                        <th class="px-3 py-3 font-medium">العملية</th>
                        <th class="px-3 py-3 font-medium">القناة</th>
                        <th class="px-3 py-3 font-medium">النتيجة</th>
                        <th class="px-3 py-3 font-medium">المزوّد:النموذج</th>
                        <th class="px-3 py-3 font-medium">الوحدات</th>
                        <th class="px-3 py-3 font-medium">مصدر التكلفة</th>
                        @if ($showCosts)
                            <th class="px-3 py-3 font-medium">التكلفة</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @if ($events)
                        @forelse ($events as $event)
                            <tr class="hover:bg-slate-50" wire:key="usage-{{ $event->id }}">
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-slate-500" dir="ltr">{{ $event->occurred_at?->format('Y-m-d H:i:s') }}</td>
                                <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $event->subscriber_id ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $event->operation ?? $event->type }}</td>
                                <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $event->channel ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs">{{ $event->outcome?->value === 'succeeded' ? 'نجحت' : ($event->outcome?->value === 'downstream_failed' ? 'فشل لاحق' : '—') }}</td>
                                <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $event->provider }}:{{ $event->model }}</td>
                                <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $event->input_units }} / {{ $event->output_units }}</td>
                                <td class="px-3 py-2 text-xs">
                                    @if ($event->hasKnownCost())
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-800">{{ $event->cost_source->label() }}</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">{{ $event->cost_source?->label() ?? \App\Services\Usage\UsageQuery::reasonLabel('legacy') }}</span>
                                    @endif
                                </td>
                                @if ($showCosts)
                                    <td class="px-3 py-2 font-mono text-xs" dir="ltr">
                                        @if ($event->hasKnownCost())
                                            {{ $event->total_cost }} {{ $event->currency }}
                                        @else
                                            <span class="text-amber-700">غير معروفة</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-10 text-center text-slate-400">لا توجد أحداث في هذا النطاق.</td></tr>
                        @endforelse
                    @endif
                </tbody>
            </table>
        </div>
        @if ($events)
            <div class="border-t border-slate-100 px-4 py-3">{{ $events->links() }}</div>
        @endif
    </div>
</div>
