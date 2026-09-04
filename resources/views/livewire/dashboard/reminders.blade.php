<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">التذكيرات</h1>
        <p class="mt-1 text-sm text-slate-500">جميع التذكيرات مرتّبة حسب موعد التذكير.</p>
    </header>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[680px] text-right text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">العنوان</th>
                        <th class="px-4 py-3 font-medium">المستخدم</th>
                        <th class="px-4 py-3 font-medium">الموعد</th>
                        <th class="px-4 py-3 font-medium">القناة</th>
                        <th class="px-4 py-3 font-medium">الحالة</th>
                        <th class="px-4 py-3 font-medium">المحاولات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($reminders as $reminder)
                        <tr class="hover:bg-slate-50">
                            <td class="max-w-[240px] truncate px-4 py-3 text-slate-800" title="{{ $reminder->title }}">{{ $reminder->title }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $reminder->user?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $reminder->remind_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $reminder->channel?->value ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ $reminder->status?->value ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ (int) $reminder->attempts }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-slate-400">لا توجد تذكيرات بعد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $reminders->links() }}
    </div>
</div>
