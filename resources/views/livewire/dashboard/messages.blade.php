<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">الرسائل</h1>
        <p class="mt-1 text-sm text-slate-500">أحدث الرسائل الواردة والصادرة.</p>
    </header>

    @php
        $dir = ['inbound' => 'واردة', 'outbound' => 'صادرة'];
    @endphp

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-right text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">الاتجاه</th>
                        <th class="px-4 py-3 font-medium">النوع</th>
                        <th class="px-4 py-3 font-medium">المحتوى</th>
                        <th class="px-4 py-3 font-medium">المعالجة</th>
                        <th class="px-4 py-3 font-medium">التسليم</th>
                        <th class="px-4 py-3 font-medium">التاريخ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($messages as $message)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-medium',
                                    'bg-sky-50 text-sky-700' => $message->direction?->value === 'inbound',
                                    'bg-emerald-50 text-emerald-700' => $message->direction?->value === 'outbound',
                                ])>
                                    {{ $dir[$message->direction?->value] ?? ($message->direction?->value ?? '—') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $message->type?->value ?? '—' }}</td>
                            <td class="max-w-[280px] truncate px-4 py-3 text-slate-800" title="{{ $message->text_content }}">
                                {{ \Illuminate\Support\Str::limit((string) $message->text_content, 80) ?: '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $message->processing_status?->value ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $message->delivery_status?->value ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $message->created_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-slate-400">لا توجد رسائل بعد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $messages->links() }}
    </div>
</div>
