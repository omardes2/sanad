<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">المشتركون</h1>
        <p class="mt-1 text-sm text-slate-500">كل رقم واتساب هو مشترك مستقل بحالة اشتراك خاصّة.</p>
    </header>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[680px] text-right text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">المشترك</th>
                        <th class="px-4 py-3 font-medium">الهاتف</th>
                        <th class="px-4 py-3 font-medium">الباقة</th>
                        <th class="px-4 py-3 font-medium">حالة الاشتراك</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($subscribers as $subscriber)
                        @php $sub = $subscriber->subscription; @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-800">{{ $subscriber->name }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500" dir="ltr">
                                {{-- last 4 only — keep full numbers off the list view --}}
                                {{ $subscriber->phone ? '***'.substr($subscriber->phone, -4) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $sub?->plan?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($sub)
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ $sub->status->label() }}</span>
                                @else
                                    <span class="text-slate-400">بلا اشتراك</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('dashboard.subscribers.show', $subscriber) }}" wire:navigate class="text-emerald-700 hover:underline">تفاصيل</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">لا يوجد مشتركون بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $subscribers->links() }}</div>
</div>
