<div class="overflow-x-auto" data-testid="{{ $testid }}">
    <table class="w-full min-w-[420px] text-right text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 font-medium">المجموعة</th><th class="px-3 py-2 font-medium">الصفوف</th><th class="px-3 py-2 font-medium">غير مسعَّر</th><th class="px-3 py-2 font-medium">التكلفة المعروفة</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($buckets as $bucket)
                <tr wire:key="{{ $testid }}-{{ md5(json_encode($bucket->dimensions)) }}">
                    <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $label($bucket) }}</td>
                    <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $bucket->rows }}</td>
                    <td class="px-3 py-2 font-mono text-xs text-amber-800" dir="ltr">{{ $bucket->unpricedRows }}</td>
                    <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $bucket->knownCost }} {{ $currency }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400">لا أحداث.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
