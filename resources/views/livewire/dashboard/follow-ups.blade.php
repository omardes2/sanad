<div>
    <header class="mb-4">
        <h1 class="text-2xl font-bold text-slate-800">المتابعة حتى الإنجاز</h1>
        <p class="mt-1 text-sm text-slate-500">بيانات تشغيلية فقط.</p>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="privacy-notice">
        <strong>لا تعرض هذه الصفحة نصّ سؤال المتابعة</strong> — وهو كلام المشترك عن أمر لم يُحسم في حياته.
        خدمة الاستعلام التي تغذّي هذه الصفحة <strong>لا تستطيع</strong> قراءة العمود أصلًا، فالحجب بنيوي لا تنسيقي.
        وكل سؤال تشغيلي هنا — لماذا سأل سَنَد مرّتين، لماذا هذه المتابعة موقوفة، كم بقي من الميزانية، متى السؤال القادم — يُجاب بلا قراءته.
    </div>

    {{-- ─── Platform totals ──────────────────────────────────────────────── --}}
    <section class="mb-6 grid gap-3 md:grid-cols-4" data-testid="overview">
        @foreach ([
            'مفتوحة (حيّة)' => $overview['live'],
            'بانتظار جواب' => $overview['awaiting'],
            'موقوفة تشغيليًا' => $overview['blocked'],
            'مشتركون لديهم متابعات' => $overview['subscribers'],
            'أُنجزت' => $overview['resolved'],
            'توقّفت بنفاد الميزانية' => $overview['abandoned'],
            'ألغاها المشترك' => $overview['cancelled'],
        ] as $label => $value)
            <div class="rounded-2xl border border-slate-200 bg-white p-3">
                <p class="text-[11px] text-slate-500">{{ $label }}</p>
                <p class="text-2xl font-bold text-slate-800" dir="ltr">{{ $value }}</p>
            </div>
        @endforeach
    </section>

    {{-- ─── Filters ──────────────────────────────────────────────────────── --}}
    <section class="mb-4 grid gap-3 md:grid-cols-3" data-testid="filters">
        <label class="text-xs text-slate-600">
            الحالة
            <select wire:model.live="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                <option value="">الكل</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs text-slate-600">
            سبب الإيقاف التشغيلي
            <select wire:model.live="blocked_reason" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                <option value="">الكل</option>
                @foreach ($reasons as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs text-slate-600">
            معرّف المشترك
            <input wire:model.live.debounce.400ms="subscriber_id" type="text" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" />
        </label>
    </section>

    {{-- ─── The listing ──────────────────────────────────────────────────── --}}
    <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white" data-testid="follow-ups">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-[11px] text-slate-500">
                <tr>
                    <th class="p-2 text-right">#</th>
                    <th class="p-2 text-right">المشترك</th>
                    <th class="p-2 text-right">الحالة</th>
                    <th class="p-2 text-right">الأسئلة</th>
                    <th class="p-2 text-right">السؤال القادم</th>
                    <th class="p-2 text-right">سبب الإيقاف</th>
                    <th class="p-2 text-right">مهمة مرتبطة</th>
                    <th class="p-2 text-right">أُنشئت</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($followUps as $followUp)
                    <tr class="border-t border-slate-100">
                        <td class="p-2" dir="ltr">{{ $followUp->id }}</td>
                        <td class="p-2">{{ $followUp->user?->name ?? '—' }} <span class="text-slate-400" dir="ltr">#{{ $followUp->user_id }}</span></td>
                        <td class="p-2">{{ $followUp->status->label() }}</td>
                        <td class="p-2" dir="ltr">{{ $followUp->asksUsed() }}/{{ $followUp->max_asks }}</td>
                        <td class="p-2" dir="ltr">{{ $followUp->next_ask_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="p-2">{{ $followUp->blocked_reason?->label() ?? '—' }}</td>
                        <td class="p-2" dir="ltr">{{ $followUp->task_id ? '#'.$followUp->task_id : '—' }}</td>
                        <td class="p-2" dir="ltr">{{ $followUp->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-4 text-center text-slate-400">لا توجد متابعات مطابقة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="mt-4">{{ $followUps->links() }}</div>
</div>
