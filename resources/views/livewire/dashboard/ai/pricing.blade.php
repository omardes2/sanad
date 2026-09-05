<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">الأسعار</h1>
        <p class="mt-1 text-sm text-slate-500">دفتر أسعار تاريخي، إضافة فقط: الفترة الجديدة تُغلق الفترة المفتوحة عند بدايتها، ولا تُعدَّل فترة موجودة أبدًا ولا يُلمس أي حدث استخدام مسجَّل. الأسعار لكل مليون token.</p>
    </header>

    @if ($notice)
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    @if ($publishing !== null)
        <section class="mb-6 rounded-2xl border border-emerald-200 bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">نشر فترة سعر جديدة — {{ $models->firstWhere('id', $publishing)?->handle() }}</h2>
            @if ($problems !== [])
                <div class="mb-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-inside list-disc space-y-1">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                </div>
            @endif
            <div class="grid gap-3 md:grid-cols-4">
                <label class="block text-sm"><span class="text-slate-600">العملة</span>
                    <input type="text" wire:model="form.currency" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">Input / 1M</span>
                    <input type="text" wire:model="form.input" dir="ltr" inputmode="decimal" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">Output / 1M</span>
                    <input type="text" wire:model="form.output" dir="ltr" inputmode="decimal" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">Cached input / 1M (اختياري)</span>
                    <input type="text" wire:model="form.cached" dir="ltr" inputmode="decimal" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">لكل طلب</span>
                    <input type="text" wire:model="form.per_request" dir="ltr" inputmode="decimal" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">سريان من</span>
                    <input type="datetime-local" wire:model="form.effective_from" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">حتى (اختياري، غير شامل)</span>
                    <input type="datetime-local" wire:model="form.effective_until" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">ملاحظة</span>
                    <input type="text" wire:model="form.note" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            </div>

            @if ($preview)
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p class="font-semibold">معاينة قبل النشر</p>
                    <dl class="mt-2 grid gap-1 md:grid-cols-2" dir="ltr">
                        <dt class="text-xs text-amber-700">Model</dt><dd class="font-mono">{{ $preview['model'] }}</dd>
                        <dt class="text-xs text-amber-700">Input / Output / Cached (per 1M)</dt><dd class="font-mono">{{ $preview['input'] }} / {{ $preview['output'] }} / {{ $preview['cached'] }} {{ $preview['currency'] }}</dd>
                        <dt class="text-xs text-amber-700">Per request</dt><dd class="font-mono">{{ $preview['per_request'] }}</dd>
                        <dt class="text-xs text-amber-700">Effective</dt><dd class="font-mono">{{ $preview['from'] }} → {{ $preview['until'] }}</dd>
                        <dt class="text-xs text-amber-700">Example: 1000 in + 300 out</dt><dd class="font-mono font-bold">{{ $preview['example'] }}</dd>
                    </dl>
                    @if ($preview['closes'])
                        <p class="mt-2">ستُغلق الفترة المفتوحة الحالية #{{ $preview['closes']['id'] }} (من <span dir="ltr">{{ $preview['closes']['from'] }}</span>) عند بداية الفترة الجديدة.</p>
                    @endif
                    @if ($preview['backdated'])
                        <p class="mt-2 font-semibold">تنبيه: البداية في الماضي. الأحداث المسجَّلة سابقًا لن يُعاد تسعيرها؛ أي تداخل مع فترة موجودة سيُرفض.</p>
                    @endif
                </div>
            @endif

            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="previewPublication" class="rounded-lg border border-emerald-600 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50">معاينة</button>
                @if ($preview)
                    <button type="button" wire:click="publish" wire:confirm="نشر فترة السعر هذه؟ لا يمكن تعديلها لاحقًا، فقط نشر فترة أحدث." class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">تأكيد النشر</button>
                @endif
                <button type="button" wire:click="cancel" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700">إلغاء</button>
            </div>
        </section>
    @endif

    @forelse ($models as $model)
        <section class="mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" wire:key="pricing-{{ $model->id }}">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-5 py-3">
                <div>
                    <span class="font-mono text-sm text-slate-800" dir="ltr">{{ $model->handle() }}</span>
                    @php $open = $model->prices->firstWhere('effective_until', null); $current = $model->prices->first(fn ($p) => $p->coversAt($now)); @endphp
                    @if ($current)
                        <span class="ms-2 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-800" dir="ltr">now: {{ $current->input_per_million }} / {{ $current->output_per_million }} {{ $current->currency }}</span>
                    @else
                        <span class="ms-2 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-800">بلا سعر ساري — الاستخدام يُسجَّل غير مسعّر</span>
                    @endif
                </div>
                @if ($canManage)
                    <button type="button" wire:click="start({{ $model->id }})" class="rounded-lg border border-slate-300 px-3 py-1 text-xs text-slate-700 hover:bg-slate-50">نشر سعر</button>
                @endif
            </div>
            @if ($model->prices->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-right text-xs">
                        <thead class="text-slate-500"><tr>
                            <th class="px-4 py-2 font-medium">#</th><th class="px-4 py-2 font-medium">من</th><th class="px-4 py-2 font-medium">حتى</th>
                            <th class="px-4 py-2 font-medium">Input</th><th class="px-4 py-2 font-medium">Output</th><th class="px-4 py-2 font-medium">Cached</th><th class="px-4 py-2 font-medium">/طلب</th>
                            <th class="px-4 py-2 font-medium">العملة</th><th class="px-4 py-2 font-medium">المصدر</th><th class="px-4 py-2 font-medium">ملاحظة</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100 font-mono" dir="ltr">
                            @foreach ($model->prices as $price)
                                <tr wire:key="price-{{ $price->id }}" @class(['bg-emerald-50/40' => $current && $current->id === $price->id])>
                                    <td class="px-4 py-2">{{ $price->id }}</td>
                                    <td class="px-4 py-2">{{ $price->effective_from?->toIso8601String() }}</td>
                                    <td class="px-4 py-2">{{ $price->effective_until?->toIso8601String() ?? 'open' }}</td>
                                    <td class="px-4 py-2">{{ $price->input_per_million }}</td>
                                    <td class="px-4 py-2">{{ $price->output_per_million }}</td>
                                    <td class="px-4 py-2">{{ $price->cached_input_per_million ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $price->per_request }}</td>
                                    <td class="px-4 py-2">{{ $price->currency }}</td>
                                    <td class="px-4 py-2">{{ $price->source?->value }}</td>
                                    <td class="px-4 py-2 font-sans" dir="rtl">{{ $price->note ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @empty
        <div class="rounded-2xl border border-slate-200 bg-white px-4 py-10 text-center text-slate-400">لا نماذج في الكتالوج بعد.</div>
    @endforelse
</div>
