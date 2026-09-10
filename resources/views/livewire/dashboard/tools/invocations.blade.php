<div>
    <header class="mb-4">
        <h1 class="text-2xl font-bold text-slate-800">استدعاءات الأدوات</h1>
        <p class="mt-1 text-sm text-slate-500">
            ما اقترحه النموذج وما قرّره الخادم. <strong>لا تُخزَّن وسائط الأدوات أصلًا</strong>: العمود <span dir="ltr">input</span> يحمل فقط ما تسمح به
            سياسة الحفظ (وهو فارغ لكل أداة شُحنت حتى الآن)، و<span dir="ltr">input_fields</span> يحمل أسماء الحقول لا قيمها.
        </p>
    </header>

    @if ($error)
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800" data-testid="error">{{ $error }}</div>
    @endif

    {{-- ─── Filters ──────────────────────────────────────────────────────── --}}
    <section class="mb-4 grid gap-3 md:grid-cols-4" data-testid="filters">
        <label class="block text-sm"><span class="text-slate-600">من</span><input type="date" wire:model.live="from" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-from"></label>
        <label class="block text-sm"><span class="text-slate-600">إلى</span><input type="date" wire:model.live="to" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-to"></label>
        <label class="block text-sm"><span class="text-slate-600">المشترك (id)</span><input type="text" wire:model.live.debounce.400ms="subscriber_id" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-subscriber"></label>
        <label class="block text-sm"><span class="text-slate-600">الأداة</span>
            <select wire:model.live="tool_key" class="mt-1 w-full rounded-lg border-slate-300 text-sm" dir="ltr" data-testid="filter-tool">
                <option value="">— الكل —</option>
                @foreach ($toolKeys as $key)<option value="{{ $key }}">{{ $key }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">الحالة</span>
            <select wire:model.live="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-status">
                <option value="">— الكل —</option>
                @foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">القدرة</span>
            <select wire:model.live="capability" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-capability">
                <option value="">— الكل —</option>
                @foreach ($capabilities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">الأثر الجانبي</span>
            <select wire:model.live="side_effect" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-side-effect">
                <option value="">— الكل —</option>
                @foreach ($sideEffects as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">نوع الفشل</span>
            <select wire:model.live="failure_kind" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-failure-kind">
                <option value="">— الكل —</option>
                @foreach ($failureKinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">سبب الرفض</span>
            <select wire:model.live="refusal_reason" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-refusal-reason">
                <option value="">— الكل —</option>
                @foreach ($refusalReasons as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm md:col-span-2"><span class="text-slate-600">مفتاح التكرار (مطابقة تامة)</span><input type="text" wire:model.live.debounce.400ms="idempotency_key" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-idempotency-key"></label>
        <label class="block text-sm md:col-span-2"><span class="text-slate-600">بصمة المدخل (مطابقة تامة)</span><input type="text" wire:model.live.debounce.400ms="input_hash" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-input-hash"></label>
    </section>
    <p class="mb-4 text-xs text-slate-500">النطاق الأقصى {{ $maxDays }} يومًا. البحث بالمعرِّفات فقط — لا يوجد بحث نصّي في المدخلات لأنها لا تُحفظ.</p>

    {{-- ─── Totals ───────────────────────────────────────────────────────── --}}
    @if ($totals)
        <section class="mb-5" data-testid="totals">
            <div class="mb-2 rounded-2xl border border-slate-200 bg-white p-3">
                <p class="text-[11px] text-slate-500">الإجمالي في النطاق</p>
                <p class="text-lg font-bold text-slate-800" dir="ltr" data-testid="total-count">{{ $totals['total'] }}</p>
            </div>
            <div class="grid gap-3 md:grid-cols-3">
                @foreach ([
                    'by_status' => ['الحالة', $statuses],
                    'by_failure_kind' => ['نوع الفشل', $failureKinds],
                    'by_refusal_reason' => ['سبب الرفض', $refusalReasons],
                ] as $bucket => [$label, $labels])
                    <div class="rounded-2xl border border-slate-200 bg-white p-3" data-testid="totals-{{ $bucket }}">
                        <p class="mb-1 text-[11px] font-semibold text-slate-500">{{ $label }}</p>
                        @forelse ($totals[$bucket] as $value => $count)
                            <p class="flex justify-between text-sm text-slate-700"><span>{{ $labels[$value] ?? $value }}</span><span dir="ltr" class="font-bold">{{ $count }}</span></p>
                        @empty
                            <p class="text-xs text-slate-400">—</p>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ─── Table ────────────────────────────────────────────────────────── --}}
    @if ($invocations)
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">#</th>
                        <th class="px-4 py-3 text-start font-medium">الأداة</th>
                        <th class="px-4 py-3 text-start font-medium">المشترك</th>
                        <th class="px-4 py-3 text-start font-medium">الحالة</th>
                        <th class="px-4 py-3 text-start font-medium">السبب</th>
                        <th class="px-4 py-3 text-start font-medium">المدّة</th>
                        <th class="px-4 py-3 text-start font-medium">التاريخ</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($invocations as $invocation)
                    <tr class="border-t border-slate-100" data-testid="invocation-{{ $invocation->id }}">
                        <td class="px-4 py-3"><a href="{{ route('dashboard.tools.invocations.show', $invocation) }}" wire:navigate class="font-semibold text-emerald-700 hover:underline" dir="ltr">{{ $invocation->id }}</a></td>
                        <td class="px-4 py-3" dir="ltr">{{ $invocation->toolKeyValue() }}</td>
                        <td class="px-4 py-3" dir="ltr">#{{ $invocation->subscriber_id }}</td>
                        <td class="px-4 py-3">{{ $invocation->status?->label() ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs text-slate-600">
                            {{ $invocation->refusal_reason?->label() ?? $invocation->failure_kind?->label() ?? '—' }}
                        </td>
                        <td class="px-4 py-3" dir="ltr">{{ $invocation->duration_ms !== null ? $invocation->duration_ms.'ms' : '—' }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500" dir="ltr">{{ $invocation->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500" data-testid="empty">لا توجد استدعاءات في هذا النطاق.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $invocations->links() }}</div>
    @endif
</div>
