<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">ذاكرة المشترك <span dir="ltr">#{{ $subscriber->id }}</span></h1>
            <p class="mt-1 text-sm text-slate-500">بيانات تشغيلية فقط — بلا محتوى وبلا بصمة.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('dashboard.memory') }}" wire:navigate class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">عودة</a>
            @if ($canSeeConsents)
                <a href="{{ route('dashboard.tools.consents', ['subscriber_id' => $subscriber->id]) }}" wire:navigate class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="consents-link">موافقات هذا المشترك</a>
            @endif
        </div>
    </header>

    {{-- ─── Consent + readability ────────────────────────────────────────── --}}
    <section class="mb-6 grid gap-3 md:grid-cols-4" data-testid="state">
        <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <p class="text-[11px] text-slate-500">موافقة القراءة</p>
            <p @class(['text-lg font-bold', 'text-emerald-700' => $readConsent->granted(), 'text-slate-600' => ! $readConsent->granted()])
               data-testid="read-consent">{{ $readConsent->granted() ? 'ممنوحة' : 'غير ممنوحة' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <p class="text-[11px] text-slate-500">موافقة الكتابة</p>
            <p @class(['text-lg font-bold', 'text-emerald-700' => $writeConsent->granted(), 'text-slate-600' => ! $writeConsent->granted()])
               data-testid="write-consent">{{ $writeConsent->granted() ? 'ممنوحة' : 'غير ممنوحة' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <p class="text-[11px] text-slate-500">التشفير متاح</p>
            <p @class(['text-lg font-bold', 'text-emerald-700' => $available, 'text-rose-700' => ! $available])>{{ $available ? 'نعم' : 'لا' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <p class="text-[11px] text-slate-500">كل الصفوف تُفتح بالمفتاح الحالي</p>
            <p @class(['text-lg font-bold', 'text-emerald-700' => $readable, 'text-rose-700' => ! $readable])
               data-testid="readable">{{ $readable ? 'نعم' : 'لا' }}</p>
        </div>
    </section>

    @unless ($readable)
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-testid="unreadable-notice">
            صفٌّ واحد على الأقل في المجموعة النشطة لا يُفتح بالمفتاح الحالي.
            القراءة الحيّة <strong>تتخطّى</strong> الصف حتى لا يُسقِط صفٌّ تالف ردًّا كاملًا؛ وإعادة التشغيل المؤكَّدة <strong>ترفض</strong>،
            لأن الرجوع بنتيجة ناقصة يخبر النموذج أن الذاكرة غير موجودة بينما الحقيقة أنها غير مقروءة.
        </div>
    @endunless

    {{-- ─── Counts ───────────────────────────────────────────────────────── --}}
    <section class="mb-6" data-testid="summary">
        <div class="mb-3 grid gap-3 md:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-3">
                <p class="text-[11px] text-slate-500">نشطة</p>
                <p class="text-2xl font-bold text-slate-800" dir="ltr" data-testid="active-count">
                    {{ $summary['active'] }} <span class="text-sm text-slate-400">/ {{ $summary['ceiling'] }}</span>
                </p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-3">
                <p class="text-[11px] text-slate-500">مؤرشَفة</p>
                <p class="text-2xl font-bold text-slate-800" dir="ltr" data-testid="archived-count">{{ $summary['archived'] }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-3">
                <p class="text-[11px] text-slate-500">عند السقف</p>
                <p @class(['text-2xl font-bold', 'text-amber-700' => $summary['at_ceiling'], 'text-slate-800' => ! $summary['at_ceiling']])>{{ $summary['at_ceiling'] ? 'نعم' : 'لا' }}</p>
            </div>
        </div>
        @if ($summary['at_ceiling'])
            <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900" data-testid="ceiling-notice">
                عند السقف <strong>تُرفَض</strong> الذاكرة الجديدة بـ<span dir="ltr">memory_capacity_reached</span> ولا يُطرَد شيء:
                ذاكرة صريحة لم يطلب المشترك نسيانها لا تُزاح لإفساح مكان لأحدث منها.
            </p>
        @endif
    </section>

    {{-- ─── Filters ──────────────────────────────────────────────────────── --}}
    <section class="mb-4 grid gap-3 md:grid-cols-4" data-testid="filters">
        <label class="block text-sm"><span class="text-slate-600">التصنيف</span>
            <select wire:model.live="category" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-category">
                <option value="">— الكل —</option>
                @foreach ($categories as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">المصدر</span>
            <select wire:model.live="provenance" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-provenance">
                <option value="">— الكل —</option>
                @foreach ($provenances as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">الحالة</span>
            <select wire:model.live="state" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-state">
                <option value="">— الكل —</option>
                <option value="active">نشطة</option>
                <option value="archived">مؤرشَفة</option>
            </select>
        </label>
        <label class="block text-sm"><span class="text-slate-600">الأهمية</span><input type="text" wire:model.live.debounce.400ms="importance" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-importance"></label>
    </section>
    <p class="mb-4 text-xs text-slate-500">لا يوجد بحث في المحتوى: العمود مشفَّر، والبحث فيه مستحيل بحكم التصميم — وممنوع بحكم السياسة.</p>

    {{-- ─── Rows: metadata only ──────────────────────────────────────────── --}}
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">#</th>
                    <th class="px-4 py-3 text-start font-medium">التصنيف</th>
                    <th class="px-4 py-3 text-start font-medium">المصدر</th>
                    <th class="px-4 py-3 text-start font-medium">الأهمية</th>
                    <th class="px-4 py-3 text-start font-medium">الحالة</th>
                    <th class="px-4 py-3 text-start font-medium">رسالة المصدر</th>
                    <th class="px-4 py-3 text-start font-medium">آخر تأكيد</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-slate-100" data-testid="memory-{{ $row->id }}">
                    <td class="px-4 py-3" dir="ltr">{{ $row->id }}</td>
                    <td class="px-4 py-3">{{ $categories[$row->category?->value] ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $provenances[$row->provenance?->value] ?? '—' }}</td>
                    <td class="px-4 py-3" dir="ltr">{{ $row->importance }}</td>
                    <td class="px-4 py-3">
                        <span @class([
                            'rounded px-2 py-0.5 text-xs font-semibold',
                            'bg-emerald-100 text-emerald-800' => $row->archived_at === null,
                            'bg-slate-100 text-slate-600' => $row->archived_at !== null,
                        ])>{{ $row->archived_at === null ? 'نشطة' : 'مؤرشَفة' }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs" dir="ltr">{{ $row->source_message_id !== null ? '#'.$row->source_message_id : '—' }}</td>
                    <td class="px-4 py-3 text-xs text-slate-500" dir="ltr">{{ $row->updated_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500" data-testid="empty">لا توجد ذاكرات مطابقة.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $rows->links() }}</div>
</div>
