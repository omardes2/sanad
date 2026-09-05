<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">مزوّدو الذكاء الاصطناعي</h1>
        <p class="mt-1 text-sm text-slate-500">
            بيانات تشغيلية فقط. التفضيل الفعلي للتوجيه هو <code dir="ltr" class="rounded bg-slate-100 px-1">AI_PROVIDER={{ $preferred }}</code>؛
            مصدر الكتالوج: <span class="font-medium">{{ $sourceMode }}</span> (الفعّال الآن: <span class="font-medium">{{ $sourceActive }}</span>).
            لا مفاتيح ولا اختبار اتصال هنا (مرحلة C3)؛ <code dir="ltr" class="rounded bg-slate-100 px-1">is_primary</code> للقراءة فقط (C4)؛ <code dir="ltr" class="rounded bg-slate-100 px-1">base_url</code> يُخزَّن ولا يُطبَّق.
        </p>
    </header>

    @if ($notice)
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if (! $editing)
        @include('livewire.dashboard.ai._confirm')
    @endif

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-right text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">المزوّد</th>
                        <th class="px-4 py-3 font-medium">Driver</th>
                        <th class="px-4 py-3 font-medium">الحالة</th>
                        <th class="px-4 py-3 font-medium">الأولوية</th>
                        <th class="px-4 py-3 font-medium">المفتاح في البيئة</th>
                        <th class="px-4 py-3 font-medium">base_url</th>
                        <th class="px-4 py-3 font-medium">النماذج</th>
                        @if ($canManage)<th class="px-4 py-3 font-medium"></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 align-top">
                    @forelse ($rows as $row)
                        @php $p = $row['provider']; @endphp
                        <tr class="hover:bg-slate-50" wire:key="provider-{{ $p->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-800">{{ $p->name }}</div>
                                <code class="text-xs text-slate-500" dir="ltr">{{ $p->key }}</code>
                                @if ($p->is_primary)<span class="ms-1 rounded-full bg-slate-200 px-2 py-0.5 text-[11px]">primary (غير مُطبَّق)</span>@endif
                                @if ($p->key === $preferred)<span class="ms-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-800">مفضَّل (AI_PROVIDER)</span>@endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs" dir="ltr">{{ $p->driver }}@if (! $row['driver_known']) <span class="text-rose-600">(لا adapter)</span>@endif</td>
                            <td class="px-4 py-3">
                                @if ($p->is_enabled)<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">مفعّل</span>@else<span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-700">معطّل</span>@endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs" dir="ltr">{{ $p->priority }}</td>
                            <td class="px-4 py-3 text-xs">
                                @if ($row['configured'] === true)<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-800">موجود</span>
                                @elseif ($row['configured'] === false)<span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">غير موجود</span>
                                @else<span class="text-slate-400">—</span>@endif
                                @if ($p->credentials_ref)<div class="mt-1 font-mono text-[11px] text-slate-400" dir="ltr">{{ $p->credentials_ref }}</div>@endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs" dir="ltr">
                                {{ $p->base_url ?: '—' }}
                                @if ($p->base_url)<span class="ms-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500">مخزَّن فقط</span>@endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs" dir="ltr">{{ $p->models_count }}</td>
                            @if ($canManage)
                                <td class="px-4 py-3 text-left">
                                    <button type="button" wire:click="edit({{ $p->id }})" class="rounded-lg border border-slate-300 px-3 py-1 text-xs text-slate-700 hover:bg-slate-50">تعديل</button>
                                </td>
                            @endif
                        </tr>
                        @if ($canManage && $editing === $p->id)
                            <tr wire:key="provider-edit-{{ $p->id }}">
                                <td colspan="8" class="bg-slate-50 px-4 py-4">
                                    @include('livewire.dashboard.ai._confirm')
                                    <div class="grid gap-3 md:grid-cols-4">
                                        <label class="block text-sm"><span class="text-slate-600">الاسم</span>
                                            <input type="text" wire:model="form.name" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                                        <label class="block text-sm md:col-span-2"><span class="text-slate-600">base_url (https فقط — يُخزَّن ولا يُطبَّق)</span>
                                            <input type="text" wire:model="form.base_url" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                                        <label class="block text-sm"><span class="text-slate-600">الأولوية</span>
                                            <input type="number" wire:model="form.priority" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                                        <label class="block text-sm"><span class="text-slate-600">الحالة</span>
                                            <select wire:model="form.is_enabled" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                                                <option value="1">مفعّل</option><option value="0">معطّل</option>
                                            </select></label>
                                        <div class="text-sm md:col-span-3"><span class="text-slate-600">القدرات</span>
                                            <div class="mt-1 flex flex-wrap gap-3">
                                                @foreach ($operations as $op)
                                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" wire:model="form.capabilities" value="{{ $op->value }}" class="rounded border-slate-300"> {{ $op->label() }}</label>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-3 flex gap-2">
                                        <button type="button" wire:click="save" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">حفظ</button>
                                        <button type="button" wire:click="cancel" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700">إلغاء</button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400">لا مزوّدين في الكتالوج بعد. سجّلهم بالأمر <code dir="ltr">sanad:ai:bootstrap</code>.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
