<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">صحة المزوّدين</h1>
        <p class="mt-1 text-sm text-slate-500">سجل فحوصات الصحة للقراءة فقط. الفحوصات المجدولة: <span class="font-medium">{{ $scheduled ? 'مفعّلة (المصادقة غير المفوترة كل 15 دقيقة)' : 'معطّلة' }}</span> · وضع المفاتيح: <span class="font-medium">{{ $credentialsMode }}</span> · الخزنة: {{ $vaultAvailable ? 'متاحة' : 'غير متاحة' }}. لا يُجدوَل أي استدلال مفوتر أبدًا؛ الصحة لا تغيّر التوجيه.</p>
    </header>

    <section class="mb-6 grid gap-3 md:grid-cols-3">
        @foreach ($providers as $p)
            @php $lh = $latest->get($p->id); @endphp
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" wire:key="health-card-{{ $p->id }}">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-sm" dir="ltr">{{ $p->key }}</span>
                    @if ($lh)
                        <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-emerald-100 text-emerald-800' => $lh->status->value === 'ok', 'bg-amber-100 text-amber-800' => $lh->status->value === 'degraded', 'bg-rose-100 text-rose-800' => $lh->status->value === 'failed', 'bg-slate-200 text-slate-700' => $lh->status->value === 'skipped'])>{{ $lh->status->label() }}</span>
                    @else
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">لا فحوصات بعد</span>
                    @endif
                </div>
                @if ($lh)
                    <p class="mt-2 text-xs text-slate-500">{{ $lh->kind->label() }} · {{ $lh->trigger->value }} · <span class="font-mono" dir="ltr">{{ $lh->checked_at?->format('Y-m-d H:i') }}</span></p>
                    <p class="text-[11px] text-slate-400" dir="ltr">{{ $lh->latency_ms !== null ? $lh->latency_ms.' ms' : '' }} {{ $lh->http_status ? 'HTTP '.$lh->http_status : '' }} {{ $lh->error_code ?? '' }}</p>
                @endif
            </div>
        @endforeach
    </section>

    <div class="mb-4 grid gap-3 md:grid-cols-3">
        <label class="block text-sm"><span class="text-slate-600">المزوّد</span>
            <select wire:model.live="provider" class="mt-1 w-full rounded-lg border-slate-300 text-sm" dir="ltr">
                <option value="">الكل</option>
                @foreach ($providers as $p)<option value="{{ $p->id }}">{{ $p->key }}</option>@endforeach
            </select></label>
        <label class="block text-sm"><span class="text-slate-600">النوع</span>
            <select wire:model.live="kind" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                <option value="">الكل</option><option value="connectivity">الاتصال</option><option value="auth">المصادقة</option><option value="inference">استدلال</option>
            </select></label>
        <label class="block text-sm"><span class="text-slate-600">الحالة</span>
            <select wire:model.live="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                <option value="">الكل</option><option value="ok">سليم</option><option value="degraded">متدهور</option><option value="failed">فشل</option><option value="skipped">متخطّى</option>
            </select></label>
    </div>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-right text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr>
                    <th class="px-3 py-3 font-medium">الوقت</th><th class="px-3 py-3 font-medium">المزوّد</th><th class="px-3 py-3 font-medium">النوع</th><th class="px-3 py-3 font-medium">المشغّل</th>
                    <th class="px-3 py-3 font-medium">الحالة</th><th class="px-3 py-3 font-medium">المصدر</th><th class="px-3 py-3 font-medium">الزمن</th><th class="px-3 py-3 font-medium">HTTP</th><th class="px-3 py-3 font-medium">الخطأ</th><th class="px-3 py-3 font-medium">تكلفة</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($history as $h)
                        <tr wire:key="health-{{ $h->id }}">
                            <td class="whitespace-nowrap px-3 py-2 font-mono text-xs" dir="ltr">{{ $h->checked_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $h->provider?->key }}</td>
                            <td class="px-3 py-2 text-xs">{{ $h->kind->label() }}@if ($h->candidate_base_url) <span class="text-[10px] text-slate-400">(candidate URL)</span>@endif</td>
                            <td class="px-3 py-2 text-xs">{{ $h->trigger->value }}</td>
                            <td class="px-3 py-2 text-xs">{{ $h->status->label() }}</td>
                            <td class="px-3 py-2 text-xs">{{ $h->credential_source->label() }}</td>
                            <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $h->latency_ms ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ $h->http_status ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-[11px]" dir="ltr">{{ $h->error_code ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">@if ($h->cost_incurred)<span class="text-amber-700">نعم (#{{ $h->usage_event_id }})</span>@else —@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-10 text-center text-slate-400">لا فحوصات مطابقة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 px-4 py-3">{{ $history->links() }}</div>
    </div>
</div>
