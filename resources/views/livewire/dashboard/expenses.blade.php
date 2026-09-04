<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">المصروفات</h1>
        <p class="mt-1 text-sm text-slate-500">جميع المصروفات مرتّبة حسب التاريخ.</p>
    </header>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[680px] text-right text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">المبلغ</th>
                        <th class="px-4 py-3 font-medium">الفئة</th>
                        <th class="px-4 py-3 font-medium">المتجر</th>
                        <th class="px-4 py-3 font-medium">المستخدم</th>
                        <th class="px-4 py-3 font-medium">التاريخ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($expenses as $expense)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-slate-800">
                                {{ number_format((float) $expense->amount, 2) }} {{ $expense->currency }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $expense->category ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $expense->merchant ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $expense->user?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $expense->expense_date?->format('Y-m-d') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-slate-400">لا توجد مصروفات بعد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $expenses->links() }}
    </div>
</div>
