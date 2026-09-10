<div>
    <header class="mb-4">
        <h1 class="text-2xl font-bold text-slate-800">موافقات الأدوات</h1>
        <p class="mt-1 text-sm text-slate-500">
            الموافقة تجيب «هل يجوز لسَنَد أن يفعل هذا لهذا المشترك أصلًا». الصف يحمل الحالة الحالية فقط مع رقم نسخة؛
            تاريخ التغييرات في سجل التدقيق. <strong>لا يوجد إجراء منح في اللوحة إطلاقًا</strong> — الموافقة لا يصنعها إلا المشترك نفسه.
        </p>
    </header>

    {{-- ─── Matrix ───────────────────────────────────────────────────────── --}}
    <section class="mb-5 overflow-x-auto rounded-2xl border border-slate-200 bg-white" data-testid="matrix">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">القدرة</th>
                    <th class="px-4 py-3 text-start font-medium">ممنوحة</th>
                    <th class="px-4 py-3 text-start font-medium">مسحوبة</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($capabilities as $value => $label)
                <tr class="border-t border-slate-100" data-testid="matrix-{{ $value }}">
                    <td class="px-4 py-3">{{ $label }} <span class="text-xs text-slate-400" dir="ltr">{{ $value }}</span></td>
                    <td class="px-4 py-3 font-bold text-emerald-700" dir="ltr">{{ $totals['by_capability'][$value]['granted'] ?? 0 }}</td>
                    <td class="px-4 py-3 font-bold text-slate-600" dir="ltr">{{ $totals['by_capability'][$value]['revoked'] ?? 0 }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    {{-- ─── Filters ──────────────────────────────────────────────────────── --}}
    <section class="mb-4 grid gap-3 md:grid-cols-3" data-testid="filters">
        <label class="block text-sm"><span class="text-slate-600">القدرة</span>
            <select wire:model.live="capability" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-capability">
                <option value="">— الكل —</option>
                @foreach ($capabilities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">الحالة</span>
            <select wire:model.live="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-status">
                <option value="">— الكل —</option>
                @foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">المشترك (id)</span><input type="text" wire:model.live.debounce.400ms="subscriber_id" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-subscriber"></label>
    </section>

    {{-- ─── Table ────────────────────────────────────────────────────────── --}}
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">#</th>
                    <th class="px-4 py-3 text-start font-medium">المشترك</th>
                    <th class="px-4 py-3 text-start font-medium">القدرة</th>
                    <th class="px-4 py-3 text-start font-medium">الحالة</th>
                    <th class="px-4 py-3 text-start font-medium">النسخة</th>
                    <th class="px-4 py-3 text-start font-medium">السبب</th>
                    <th class="px-4 py-3 text-start font-medium">آخر تغيير</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($consents as $consent)
                <tr class="border-t border-slate-100" data-testid="consent-{{ $consent->id }}">
                    <td class="px-4 py-3"><a href="{{ route('dashboard.tools.consents.show', $consent) }}" wire:navigate class="font-semibold text-emerald-700 hover:underline" dir="ltr">{{ $consent->id }}</a></td>
                    <td class="px-4 py-3" dir="ltr">#{{ $consent->subscriber_id }}</td>
                    <td class="px-4 py-3">{{ $consent->capability->label() }}</td>
                    <td class="px-4 py-3">
                        <span @class([
                            'rounded px-2 py-0.5 text-xs font-semibold',
                            'bg-emerald-100 text-emerald-800' => $consent->isGranted(),
                            'bg-slate-100 text-slate-600' => ! $consent->isGranted(),
                        ])>{{ $consent->status->label() }}</span>
                    </td>
                    <td class="px-4 py-3" dir="ltr">{{ $consent->version }}</td>
                    <td class="px-4 py-3 text-xs text-slate-600">{{ $consent->reason_code->label() }}</td>
                    <td class="px-4 py-3 text-xs text-slate-500" dir="ltr">{{ $consent->updated_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500" data-testid="empty">لا توجد موافقات مطابقة.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $consents->links() }}</div>
</div>
