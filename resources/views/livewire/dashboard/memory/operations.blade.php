<div>
    <header class="mb-4">
        <h1 class="text-2xl font-bold text-slate-800">الذاكرة الدائمة</h1>
        <p class="mt-1 text-sm text-slate-500">بيانات تشغيلية فقط.</p>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="privacy-notice">
        <strong>لا تعرض هذه الصفحة محتوى أي ذاكرة</strong> — لا مقتطعًا ولا مموّهًا ولا خلف زر. ولا تعرض بصمة ولا مفتاحًا.
        كل ذاكرة في V1 شيء طلب المشترك صراحةً أن يتذكّره سَنَد، ولا توجد صلاحية في المنصّة تكشف محتواها.
        البصمة تحديدًا لا تُعرض لأنها <span dir="ltr">MAC</span> مفتاحي على المحتوى: عرضها يمنح من يراها أداة تأكيد للتخمين.
    </div>

    {{-- ─── Encryption readiness ─────────────────────────────────────────── --}}
    <section class="mb-6 grid gap-3 md:grid-cols-4" data-testid="encryption">
        <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <p class="text-[11px] text-slate-500">مفتاح التشفير</p>
            <p @class(['text-lg font-bold', 'text-emerald-700' => $cipherAvailable, 'text-rose-700' => ! $cipherAvailable])>{{ $cipherAvailable ? 'مضبوط' : 'غير مضبوط' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <p class="text-[11px] text-slate-500">مفتاح البصمة</p>
            <p @class(['text-lg font-bold', 'text-emerald-700' => $fingerprintKeySet, 'text-rose-700' => ! $fingerprintKeySet])>{{ $fingerprintKeySet ? 'مضبوط' : 'غير مضبوط' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <p class="text-[11px] text-slate-500">معرّف المفتاح الفعّال</p>
            <p class="text-lg font-bold text-slate-800" dir="ltr" data-testid="key-id">{{ $keyId ?? '—' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <p class="text-[11px] text-slate-500">الحدود</p>
            <p class="text-xs text-slate-700" dir="ltr">{{ $limits['max_active'] }} نشطة · {{ $limits['max_content_chars'] }} حرف · {{ $limits['prompt_limit'] }}/{{ $limits['prompt_max_chars'] }} في الـprompt</p>
        </div>
    </section>

    @unless ($cipherAvailable && $fingerprintKeySet)
        <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900" data-testid="fail-closed">
            الذاكرة <strong>فاشلة مغلقة</strong>: بلا المفتاحين لا تُحفظ ذاكرة ولا تُقرأ ولا تصل الـprompt. الأعداد أدناه تاريخية.
        </div>
    @endunless

    {{-- ─── Platform totals ──────────────────────────────────────────────── --}}
    <section class="mb-6" data-testid="overview">
        <div class="mb-3 grid gap-3 md:grid-cols-4">
            @foreach ([
                'نشطة' => $overview['active'],
                'مؤرشَفة' => $overview['archived'],
                'مشتركون لديهم ذاكرات' => $overview['subscribers'],
                'عند السقف ('.$overview['ceiling'].')' => $overview['at_ceiling'],
            ] as $label => $value)
                <div class="rounded-2xl border border-slate-200 bg-white p-3">
                    <p class="text-[11px] text-slate-500">{{ $label }}</p>
                    <p class="text-2xl font-bold text-slate-800" dir="ltr">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-3" data-testid="by-category">
                <p class="mb-1 text-[11px] font-semibold text-slate-500">حسب التصنيف (النشطة)</p>
                @forelse ($overview['by_category'] as $category => $count)
                    <p class="flex justify-between text-sm text-slate-700"><span dir="ltr">{{ $category }}</span><span dir="ltr" class="font-bold">{{ $count }}</span></p>
                @empty
                    <p class="text-xs text-slate-400">—</p>
                @endforelse
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-3" data-testid="by-provenance">
                <p class="mb-1 text-[11px] font-semibold text-slate-500">حسب المصدر (النشطة)</p>
                @forelse ($overview['by_provenance'] as $provenance => $count)
                    <p class="flex justify-between text-sm text-slate-700"><span dir="ltr">{{ $provenance }}</span><span dir="ltr" class="font-bold">{{ $count }}</span></p>
                @empty
                    <p class="text-xs text-slate-400">—</p>
                @endforelse
                <p class="mt-2 text-[11px] text-slate-500">V1 تكتب <span dir="ltr">explicit</span> فقط؛ لا يوجد كاتب لـ<span dir="ltr">inferred</span> في الكود.</p>
            </div>
        </div>
    </section>

    {{-- ─── Subscribers ──────────────────────────────────────────────────── --}}
    <section data-testid="subscribers">
        <div class="mb-3 grid gap-3 md:grid-cols-3">
            <label class="block text-sm"><span class="text-slate-600">المشترك (id)</span><input type="text" wire:model.live.debounce.400ms="subscriber_id" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-subscriber"></label>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">المشترك</th>
                        <th class="px-4 py-3 text-start font-medium">نشطة</th>
                        <th class="px-4 py-3 text-start font-medium">مؤرشَفة</th>
                        <th class="px-4 py-3 text-start font-medium">الإجمالي</th>
                        <th class="px-4 py-3 text-start font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr class="border-t border-slate-100" data-testid="memory-subscriber-{{ $row->user_id }}">
                        <td class="px-4 py-3" dir="ltr">#{{ $row->user_id }}</td>
                        <td class="px-4 py-3 font-semibold" dir="ltr">
                            {{ $row->active }}
                            @if ((int) $row->active >= $overview['ceiling'])
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-800">عند السقف</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" dir="ltr">{{ $row->archived }}</td>
                        <td class="px-4 py-3" dir="ltr">{{ $row->total }}</td>
                        <td class="px-4 py-3"><a href="{{ route('dashboard.memory.subscriber', $row->user_id) }}" wire:navigate class="text-sm text-emerald-700 hover:underline">تفاصيل</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500" data-testid="empty">لا توجد ذاكرات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    </section>
</div>
