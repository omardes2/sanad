<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">سجل التدقيق</h1>
        <p class="mt-1 text-sm text-slate-500">كل تغيير حسّاس مسجَّل هنا للقراءة فقط. القيم السرّية لا تُخزَّن أبدًا؛ تظهر كبصمة مختصرة فقط.</p>
    </header>

    <div class="mb-4 grid gap-3 md:grid-cols-4">
        <label class="block text-sm">
            <span class="text-slate-600">الإجراء</span>
            <input type="text" wire:model.live.debounce.400ms="action" placeholder="مثال: rbac." dir="ltr"
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
        </label>
        <label class="block text-sm">
            <span class="text-slate-600">المصدر</span>
            <select wire:model.live="actor" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">الكل</option>
                <option value="user">مستخدم</option>
                <option value="console">سطر أوامر</option>
                <option value="system">النظام</option>
            </select>
        </label>
        <label class="block text-sm">
            <span class="text-slate-600">من تاريخ</span>
            <input type="date" wire:model.live="from" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
        </label>
        <label class="block text-sm">
            <span class="text-slate-600">إلى تاريخ</span>
            <input type="date" wire:model.live="to" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
        </label>
        <label class="block text-sm">
            <span class="text-slate-600">نوع الموضوع</span>
            <select wire:model.live="subject_type" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" data-testid="subject-type">
                <option value="">الكل</option>
                @foreach ($subjectTypes as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm">
            <span class="text-slate-600">معرّف الموضوع</span>
            <input type="text" wire:model.live.debounce.400ms="subject_id" dir="ltr" inputmode="numeric" placeholder="id" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" data-testid="subject-id">
        </label>
    </div>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[840px] text-right text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">الوقت (UTC)</th>
                        <th class="px-4 py-3 font-medium">الإجراء</th>
                        <th class="px-4 py-3 font-medium">الفاعل</th>
                        <th class="px-4 py-3 font-medium">الموضوع</th>
                        <th class="px-4 py-3 font-medium">التغييرات</th>
                        <th class="px-4 py-3 font-medium">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 align-top">
                    @forelse ($logs as $log)
                        <tr class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-500" dir="ltr">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-800" dir="ltr">{{ $log->action }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                @if ($log->user)
                                    {{ $log->user->name }}
                                @else
                                    {{-- Account deleted or non-user actor: the immutable snapshot --}}
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 font-mono text-xs" dir="ltr">{{ $log->actor_ref ?? $log->actor ?? '—' }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500" dir="ltr">
                                @if ($log->subject_type)
                                    <a class="hover:underline" href="{{ route('dashboard.audit', ['subject_type' => class_basename($log->subject_type), 'subject_id' => $log->subject_id]) }}">{{ class_basename($log->subject_type) }}#{{ $log->subject_id }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php $changes = $log->changes(); $context = $log->context(); @endphp
                                @if ($changes !== [] || $context !== [])
                                    <details>
                                        <summary class="cursor-pointer text-xs text-emerald-700">{{ count($changes) }} تغيير</summary>
                                        <pre class="mt-2 max-w-xl overflow-x-auto rounded-lg bg-slate-50 p-2 text-[11px] leading-snug text-slate-700" dir="ltr">{{ json_encode(['changes' => $changes, 'context' => $context], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                                    </details>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500" dir="ltr">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-slate-400">لا توجد سجلات مطابقة.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-100 px-4 py-3">
            {{ $logs->links() }}
        </div>
    </div>
</div>
