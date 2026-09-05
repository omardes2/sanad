<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">التوجيه</h1>
        <p class="mt-1 text-sm text-slate-500">ما يقرّره الموجّه فعليًا الآن، بأسباب تخطّي كل مرشّح، ومحاكاة «ماذا لو» بلا أي كتابة. لا تبديل هنا: <code dir="ltr" class="rounded bg-slate-100 px-1">AI_PROVIDER={{ $envPreferred }}</code> يبقى الحاكم (التبديل في C4).</p>
        <p class="mt-1 text-xs text-slate-400">مصدر الكتالوج: {{ $sourceMode }} — الفعّال: {{ $sourceActive }}.</p>
    </header>

    <div class="mb-4 grid gap-3 md:grid-cols-3">
        <label class="block text-sm"><span class="text-slate-600">العملية</span>
            <select wire:model.live="operation" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                @foreach ($operations as $op)<option value="{{ $op->value }}">{{ $op->label() }} ({{ $op->value }})</option>@endforeach
            </select></label>
        <label class="block text-sm"><span class="text-slate-600">ماذا لو كان المزوّد المفضّل</span>
            <select wire:model.live="preferred" class="mt-1 w-full rounded-lg border-slate-300 text-sm" dir="ltr">
                <option value="">(كما هو: {{ $envPreferred }})</option>
                @foreach ($providersKnown as $key)<option value="{{ $key }}">{{ $key }}</option>@endforeach
            </select></label>
        <label class="block text-sm"><span class="text-slate-600">ماذا لو كان حدّ التكلفة لكل طلب ({{ $currency }})</span>
            <input type="text" wire:model.live.debounce.400ms="maxUnitCost" dir="ltr" inputmode="decimal" placeholder="بلا حد" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
    </div>

    @foreach ([['title' => 'التقييم الفعلي الآن', 'rows' => $liveRows, 'evaluation' => $live], ['title' => 'المحاكاة (ماذا لو)', 'rows' => $whatIfRows, 'evaluation' => $whatIf]] as $block)
        @continue($block['evaluation'] === null)
        <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-5 py-3">
                <h2 class="text-sm font-semibold text-slate-700">{{ $block['title'] }} — المفضّل: <code dir="ltr">{{ $block['evaluation']->preferredProvider }}</code></h2>
                @if ($block['evaluation']->hasRoute())
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800" dir="ltr">selected: {{ $block['evaluation']->selectedHandle() }}</span>
                @else
                    <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs text-rose-800">لا مسار صالح لهذه العملية</span>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-right text-sm">
                    <thead class="text-xs uppercase text-slate-500"><tr>
                        <th class="px-4 py-2 font-medium">#</th>
                        <th class="px-4 py-2 font-medium">المرشّح</th>
                        <th class="px-4 py-2 font-medium">الأولوية</th>
                        <th class="px-4 py-2 font-medium">الحالة</th>
                        <th class="px-4 py-2 font-medium">السبب</th>
                        <th class="px-4 py-2 font-medium">البديل المعلَن</th>
                        @if ($showCosts)<th class="px-4 py-2 font-medium">تقدير/طلب</th>@endif
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($block['rows'] as $i => $row)
                            <tr @class(['bg-emerald-50/50' => $row['status'] === 'selected'])>
                                <td class="px-4 py-2 font-mono text-xs" dir="ltr">{{ $i + 1 }}</td>
                                <td class="px-4 py-2 font-mono text-xs" dir="ltr">{{ $row['handle'] }}</td>
                                <td class="px-4 py-2 font-mono text-xs" dir="ltr">{{ $row['priority'] }}</td>
                                <td class="px-4 py-2 text-xs">
                                    @if ($row['status'] === 'selected')<span class="rounded-full bg-emerald-600 px-2 py-0.5 text-white">المختار</span>
                                    @elseif ($row['status'] === 'eligible')<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-800">مؤهّل (بديل)</span>
                                    @else<span class="rounded-full bg-slate-200 px-2 py-0.5 text-slate-700">متخطّى</span>@endif
                                </td>
                                <td class="px-4 py-2 text-xs text-slate-600">{{ $row['reason'] ? ($reasonLabels[$row['reason']] ?? $row['reason']) : '—' }}</td>
                                <td class="px-4 py-2 font-mono text-[11px] text-slate-500" dir="ltr">{{ $row['fallback'] ?? '—' }}</td>
                                @if ($showCosts)
                                    <td class="px-4 py-2 font-mono text-xs" dir="ltr">{{ $row['estimate'] === null ? 'غير معروف' : number_format($row['estimate'], 6).' '.$currency }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-slate-400">لا مرشّحين لهذه العملية.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</div>
