{{-- $p = preview array, $kind, $readOnly, $applyMethod --}}
<div class="mt-4 space-y-3">
    <div class="grid gap-3 md:grid-cols-2">
        @foreach ([['title' => 'قبل', 'rows' => $p['before_rows'], 'selected' => $p['before']], ['title' => 'بعد', 'rows' => $p['after_rows'], 'selected' => $p['after']]] as $block)
            <div class="rounded-xl border border-slate-200 p-3">
                <p class="text-xs font-semibold text-slate-600">{{ $block['title'] }}: <code dir="ltr">{{ $block['selected'] ?? 'لا مسار' }}</code></p>
                <table class="mt-2 w-full text-right text-[11px]">
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($block['rows'] as $r)
                            <tr @class(['bg-emerald-50' => $r['status'] === 'selected'])>
                                <td class="py-1 font-mono" dir="ltr">{{ $r['handle'] }}</td>
                                <td class="py-1">{{ $r['status'] === 'selected' ? 'المختار' : ($r['status'] === 'eligible' ? 'مؤهّل' : 'متخطّى') }}</td>
                                <td class="py-1 text-slate-500">{{ $r['reason'] ? ($reasonLabels[$r['reason']] ?? $r['reason']) : '—' }}</td>
                                <td class="py-1 font-mono" dir="ltr">{{ $r['estimate'] === null ? 'COST UNKNOWN' : $r['estimate'].' '.$currency }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-center text-slate-400">لا مرشّحين</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    @if ($p['readiness'] !== [])
        <div class="rounded-xl border border-slate-200 p-3">
            <p class="text-xs font-semibold text-slate-600">الجاهزية</p>
            <ul class="mt-1 space-y-0.5 text-[11px]">
                @foreach ($p['readiness'] as $c)
                    <li>
                        <span @class(['rounded-full px-2 py-0.5', 'bg-emerald-100 text-emerald-800' => $c['status'] === 'ok', 'bg-amber-100 text-amber-800' => $c['status'] === 'warn', 'bg-rose-100 text-rose-800' => $c['status'] === 'fail'])>{{ strtoupper($c['status']) }}</span>
                        <strong>{{ $c['label'] }}</strong>: {{ $c['detail'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($p['same_route_required'])
        <p class="text-[11px] {{ $p['same_route'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $p['same_route'] ? 'المسار لا يتغيّر (مطلوب لهذه الخطوة).' : 'المسار سيتغيّر — هذه الخطوة محظورة حتى يتطابق المسار.' }}</p>
    @endif

    @if ($p['blockers'] !== [])
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-[11px] text-rose-800"><ul class="list-inside list-disc">@foreach ($p['blockers'] as $b)<li>{{ $b }}</li>@endforeach</ul></div>
    @endif
    @if ($p['warnings'] !== [])
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900"><ul class="list-inside list-disc">@foreach ($p['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul></div>
    @endif

    @if (! $readOnly && $p['applicable'])
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-3">
            <p class="text-xs text-amber-900">للتأكيد اكتب المسار الناتج <code dir="ltr">{{ $p['expected'] }}</code> (provider:model) ثم اضغط تطبيق.</p>
            <div class="mt-2 flex gap-2">
                <input type="text" wire:model="confirmations.{{ $kind }}" dir="ltr" autocomplete="off" placeholder="{{ $p['expected'] }}" class="w-72 rounded border-amber-300 text-xs">
                <button type="button" wire:click="{{ $applyMethod }}" class="rounded-lg bg-amber-600 px-3 py-1 text-xs text-white hover:bg-amber-700">تطبيق</button>
            </div>
        </div>
    @endif
</div>
