<div>
    <header class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">النماذج</h1>
            <p class="mt-1 text-sm text-slate-500">التفعيل/التعطيل وتغيير الأولوية يمرّان بمحاكاة التوجيه: يُرفض أي تغيير يترك chat بلا مسار، ويتطلب تغيير المسار المختار تأكيدًا مكتوبًا. الحذف للنماذج المعطّلة غير المرتبطة فقط.</p>
        </div>
        <button type="button" wire:click="create" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">نموذج جديد</button>
    </header>

    @if ($notice)
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($editing === null)
        @include('livewire.dashboard.ai._confirm')
    @endif

    @if ($editing !== null)
        <section class="mb-6 rounded-2xl border border-emerald-200 bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">{{ $editing === 0 ? 'نموذج جديد' : 'تعديل النموذج' }}</h2>
            @include('livewire.dashboard.ai._confirm')
            <div class="grid gap-3 md:grid-cols-4">
                <label class="block text-sm"><span class="text-slate-600">المزوّد</span>
                    <select wire:model="form.provider_id" @disabled($editing !== 0) class="mt-1 w-full rounded-lg border-slate-300 text-sm" dir="ltr">
                        <option value="">—</option>
                        @foreach ($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->key }}</option>@endforeach
                    </select></label>
                <label class="block text-sm"><span class="text-slate-600">external_id (يُرسَل للمزوّد)</span>
                    <input type="text" wire:model="form.external_id" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">الاسم</span>
                    <input type="text" wire:model="form.name" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">أسماء بديلة (مفصولة بفواصل)</span>
                    <input type="text" wire:model="form.aliases" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="text-sm md:col-span-2"><span class="text-slate-600">القدرات</span>
                    <div class="mt-1 flex flex-wrap gap-3">
                        @foreach ($operations as $op)
                            <label class="flex items-center gap-1 text-xs"><input type="checkbox" wire:model="form.capabilities" value="{{ $op->value }}" class="rounded border-slate-300"> {{ $op->label() }}</label>
                        @endforeach
                    </div>
                </div>
                <label class="block text-sm"><span class="text-slate-600">يدعم الأدوات</span>
                    <select wire:model="form.supports_tools" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="1">نعم</option><option value="0">لا</option></select></label>
                <label class="block text-sm"><span class="text-slate-600">الأولوية</span>
                    <input type="number" wire:model="form.priority" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">context_window</span>
                    <input type="number" wire:model="form.context_window" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">max_output_tokens</span>
                    <input type="number" wire:model="form.max_output_tokens" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm"><span class="text-slate-600">النموذج البديل (fallback)</span>
                    <select wire:model="form.fallback_model_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm" dir="ltr">
                        <option value="">— بلا —</option>
                        @foreach ($allModels as $m)
                            @if ($m->id !== $editing)<option value="{{ $m->id }}">{{ $m->handle() }}</option>@endif
                        @endforeach
                    </select></label>
                <label class="block text-sm"><span class="text-slate-600">الحالة</span>
                    <select wire:model="form.is_enabled" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="1">مفعّل</option><option value="0">معطّل</option></select></label>
            </div>
            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="save" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">حفظ</button>
                <button type="button" wire:click="cancel" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700">إلغاء</button>
            </div>
        </section>
    @endif

    @forelse ($providers as $provider)
        <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" wire:key="models-of-{{ $provider->id }}">
            <h2 class="border-b border-slate-100 bg-slate-50 px-5 py-3 text-sm font-semibold text-slate-700">
                <span dir="ltr">{{ $provider->key }}</span>
                @unless ($provider->is_enabled)<span class="ms-2 rounded-full bg-slate-200 px-2 py-0.5 text-[11px]">المزوّد معطّل</span>@endunless
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-right text-sm">
                    <thead class="text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">external_id</th>
                            <th class="px-4 py-2 font-medium">الاسم</th>
                            <th class="px-4 py-2 font-medium">أسماء بديلة</th>
                            <th class="px-4 py-2 font-medium">القدرات</th>
                            <th class="px-4 py-2 font-medium">الأولوية</th>
                            <th class="px-4 py-2 font-medium">البديل</th>
                            <th class="px-4 py-2 font-medium">الأسعار</th>
                            <th class="px-4 py-2 font-medium">الحالة</th>
                            <th class="px-4 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($grouped->get($provider->id, collect()) as $model)
                            <tr class="hover:bg-slate-50" wire:key="model-{{ $model->id }}">
                                <td class="px-4 py-2 font-mono text-xs" dir="ltr">{{ $model->external_id }}</td>
                                <td class="px-4 py-2">{{ $model->name }}</td>
                                <td class="px-4 py-2 font-mono text-[11px] text-slate-500" dir="ltr">{{ implode(', ', (array) ($model->aliases ?? [])) ?: '—' }}</td>
                                <td class="px-4 py-2 text-xs">{{ implode('، ', array_map(fn ($o) => $o->label(), $model->operations())) }}</td>
                                <td class="px-4 py-2 font-mono text-xs" dir="ltr">{{ $model->priority }}</td>
                                <td class="px-4 py-2 font-mono text-[11px]" dir="ltr">{{ $model->fallback?->handle() ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs" dir="ltr">{{ $model->prices_count }}</td>
                                <td class="px-4 py-2">
                                    @if ($model->is_enabled)<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">مفعّل</span>@else<span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-700">معطّل</span>@endif
                                </td>
                                <td class="px-4 py-2 text-left">
                                    <button type="button" wire:click="edit({{ $model->id }})" class="rounded-lg border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50">تعديل</button>
                                    @unless ($model->is_enabled)
                                        <button type="button" wire:click="delete({{ $model->id }})" wire:confirm="حذف النموذج {{ $model->handle() }}؟ يُرفض الحذف إن كان له أسعار أو استخدام أو كان بديلًا لغيره." class="rounded-lg border border-rose-300 px-2 py-1 text-xs text-rose-700 hover:bg-rose-50">حذف</button>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-6 text-center text-slate-400">لا نماذج لهذا المزوّد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <div class="rounded-2xl border border-slate-200 bg-white px-4 py-10 text-center text-slate-400">لا مزوّدين في الكتالوج بعد.</div>
    @endforelse
</div>
