<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">المحادثات</h1>
        <p class="mt-1 text-sm text-slate-500">جميع المحادثات مرتّبة حسب آخر رسالة.</p>
    </header>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-right text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">المستخدم</th>
                        <th class="px-4 py-3 font-medium">القناة</th>
                        <th class="px-4 py-3 font-medium">الحالة</th>
                        <th class="px-4 py-3 font-medium">الرسائل</th>
                        <th class="px-4 py-3 font-medium">آخر رسالة</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($conversations as $conversation)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-800">{{ $conversation->user?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $conversation->channelAccount?->channel?->value ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                    {{ $conversation->status?->value ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ number_format((int) $conversation->messages_count) }}</td>
                            <td class="px-4 py-3 text-slate-500">
                                {{ $conversation->last_message_at?->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-slate-400">لا توجد محادثات بعد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $conversations->links() }}
    </div>
</div>
