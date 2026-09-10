<div>
    <header class="mb-4">
        <h1 class="text-2xl font-bold text-slate-800">التذكيرات</h1>
        <p class="mt-1 text-sm text-slate-500">
            التسليم <strong>مرة على الأقل ومحدود</strong>، لا مرة واحدة بالضبط: محاولتان فعليّتان كحدّ أقصى لكل مناسبة.
            @if ($showDelivery)
                <span class="text-slate-400">رمز الحجز لا يُعرض — وهو سياج لا معلومة تشخيصية؛ ما يهمّ التشغيل يجيبه وقت الحجز والإرسال.</span>
            @endif
        </p>
    </header>

    @if ($error)
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800" data-testid="error">{{ $error }}</div>
    @endif

    {{-- ─── Filters ──────────────────────────────────────────────────────── --}}
    <section class="mb-4 grid gap-3 md:grid-cols-4" data-testid="filters">
        <label class="block text-sm"><span class="text-slate-600">من (موعد التذكير)</span><input type="date" wire:model.live="from" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-from"></label>
        <label class="block text-sm"><span class="text-slate-600">إلى</span><input type="date" wire:model.live="to" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-to"></label>
        <label class="block text-sm"><span class="text-slate-600">الحالة</span>
            <select wire:model.live="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-status">
                <option value="">— الكل —</option>
                @foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">القناة</span>
            <select wire:model.live="channel" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-channel">
                <option value="">— الكل —</option>
                @foreach ($channels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">المشترك (id)</span><input type="text" wire:model.live.debounce.400ms="subscriber_id" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-subscriber"></label>
        @if ($showDelivery)
            <label class="block text-sm"><span class="text-slate-600">سبب الفشل</span>
                <select wire:model.live="reason" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-reason">
                    <option value="">— الكل —</option>
                    @foreach ($reasons as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="block text-sm"><span class="text-slate-600">المحاولات ≥</span><input type="text" wire:model.live.debounce.400ms="attempts" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-attempts"></label>
            <label class="block text-sm"><span class="text-slate-600">الحجز</span>
                <select wire:model.live="claimed" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-claimed">
                    <option value="">— الكل —</option>
                    <option value="unsettled">محجوز بلا إرسال</option>
                    <option value="dispatched">أُرسِل فعليًا</option>
                </select>
            </label>
        @endif
    </section>
    <p class="mb-4 text-xs text-slate-500">النطاق الأقصى {{ $maxDays }} يومًا.</p>

    {{-- ─── Totals ───────────────────────────────────────────────────────── --}}
    @if ($totals)
        <section class="mb-5 grid gap-3 md:grid-cols-3" data-testid="totals">
            <div class="rounded-2xl border border-slate-200 bg-white p-3">
                <p class="mb-1 text-[11px] font-semibold text-slate-500">حسب الحالة</p>
                @forelse ($totals['by_status'] as $value => $count)
                    <p class="flex justify-between text-sm text-slate-700"><span>{{ $statuses[$value] ?? $value }}</span><span dir="ltr" class="font-bold">{{ $count }}</span></p>
                @empty
                    <p class="text-xs text-slate-400">—</p>
                @endforelse
            </div>
            @if ($showDelivery)
                <div class="rounded-2xl border border-slate-200 bg-white p-3" data-testid="totals-reasons">
                    <p class="mb-1 text-[11px] font-semibold text-slate-500">حسب سبب الفشل</p>
                    @forelse ($totals['by_reason'] as $value => $count)
                        <p class="flex justify-between text-sm text-slate-700"><span>{{ $reasons[$value] ?? $value }}</span><span dir="ltr" class="font-bold">{{ $count }}</span></p>
                    @empty
                        <p class="text-xs text-slate-400">—</p>
                    @endforelse
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-3" data-testid="totals-attempts">
                    <p class="text-[11px] font-semibold text-slate-500">المحاولات الفعلية</p>
                    <p class="text-2xl font-bold text-slate-800" dir="ltr">{{ $totals['attempts'] }}</p>
                    <p class="text-[11px] text-slate-500">عدد الطلبات الفعلية للمزوّد — ليس عدد التذكيرات: مناسبة واحدة قد تكلّف محاولتين.</p>
                </div>
            @endif
        </section>
    @endif

    {{-- ─── Table ────────────────────────────────────────────────────────── --}}
    @if ($reminders)
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">العنوان</th>
                        <th class="px-4 py-3 text-start font-medium">المشترك</th>
                        <th class="px-4 py-3 text-start font-medium">الموعد</th>
                        <th class="px-4 py-3 text-start font-medium">القناة</th>
                        <th class="px-4 py-3 text-start font-medium">الحالة</th>
                        @if ($showDelivery)
                            <th class="px-4 py-3 text-start font-medium">السبب</th>
                            <th class="px-4 py-3 text-start font-medium">المحاولات</th>
                            <th class="px-4 py-3 text-start font-medium">حُجِز</th>
                            <th class="px-4 py-3 text-start font-medium">أُرسِل</th>
                        @endif
                        <th class="px-4 py-3 text-start font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($reminders as $reminder)
                    <tr class="border-t border-slate-100" data-testid="reminder-{{ $reminder->id }}">
                        <td class="px-4 py-3">{{ $reminder->title }}</td>
                        <td class="px-4 py-3" dir="ltr">#{{ $reminder->user_id }}</td>
                        <td class="px-4 py-3 text-xs" dir="ltr">{{ $reminder->remind_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">{{ $reminder->channel?->label() ?? '—' }}</td>
                        <td class="px-4 py-3">
                            {{ $reminder->status?->label() ?? '—' }}
                            @if ($showDelivery && $reminder->isStaleClaim())
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-800" data-testid="stale-{{ $reminder->id }}">حجز قديم</span>
                            @endif
                        </td>
                        @if ($showDelivery)
                            <td class="px-4 py-3 text-xs text-slate-600" data-testid="reason-{{ $reminder->id }}">{{ $reminder->failureReason()?->label() ?? ($reminder->last_error ?? '—') }}</td>
                            <td class="px-4 py-3" dir="ltr">{{ $reminder->attempts }}</td>
                            <td class="px-4 py-3 text-xs" dir="ltr">{{ $reminder->claimed_at?->format('m-d H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" dir="ltr">{{ $reminder->dispatched_at?->format('m-d H:i') ?? '—' }}</td>
                        @endif
                        <td class="px-4 py-3"><a href="{{ route('dashboard.reminders.show', $reminder) }}" wire:navigate class="text-sm text-emerald-700 hover:underline">تفاصيل</a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $showDelivery ? 10 : 6 }}" class="px-4 py-6 text-center text-sm text-slate-500" data-testid="empty">لا توجد تذكيرات في هذا النطاق.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $reminders->links() }}</div>
    @endif
</div>
