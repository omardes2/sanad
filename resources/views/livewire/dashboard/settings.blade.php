<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">الإعدادات</h1>
        <p class="mt-1 text-sm text-slate-500">القيمة الفعّالة ومصدرها لكل إعداد. الإعدادات التشغيلية تُقرأ من قاعدة البيانات ثم الافتراضي؛ مفاتيح الطوارئ فقط تخضع لمتغيّر البيئة أولًا.</p>
    </header>

    @if ($notice)
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    @foreach ($groups as $group => $items)
        <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <h2 class="border-b border-slate-100 bg-slate-50 px-5 py-3 text-sm font-semibold text-slate-700">{{ $labels[$group] ?? $group }}</h2>
            <div class="divide-y divide-slate-100">
                @foreach ($items as $item)
                    @php
                        /** @var \App\Data\Settings\EffectiveSetting $e */
                        $e = $item['effective'];
                        $def = $e->definition;
                        $key = $def->key;
                        $formKey = \App\Livewire\Dashboard\Settings::formKey($key);
                    @endphp
                    <div class="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]" wire:key="setting-{{ $key }}">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-slate-800">{{ $def->label }}</span>
                                <code class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500" dir="ltr">{{ $key }}</code>
                            </div>
                            <p class="mt-1 text-xs leading-relaxed text-slate-500">{{ $def->description }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px]">
                                @if ($e->source === 'env')
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800">مُجبَر من البيئة ({{ $def->envKey }})</span>
                                @elseif ($e->source === 'db')
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-800">من قاعدة البيانات</span>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600">الافتراضي</span>
                                @endif
                                @if ($def->readOnly)
                                    <span class="rounded-full bg-slate-200 px-2 py-0.5 font-medium text-slate-700">للعرض فقط</span>
                                @endif
                                @if ($e->invalid)
                                    <span class="rounded-full bg-rose-100 px-2 py-0.5 font-medium text-rose-800">القيمة المخزَّنة غير صالحة وتحتاج تصحيحًا — يُستخدم الافتراضي</span>
                                @endif
                            </div>
                            @if ($e->invalid && $e->invalidReason)
                                <p class="mt-1 text-[11px] text-rose-700">{{ $e->invalidReason }}</p>
                            @endif
                        </div>

                        <div>
                            @if ($item['canEdit'])
                                <div class="flex flex-col gap-2">
                                    @if ($def->type->value === 'boolean')
                                        <select wire:model="values.{{ $formKey }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                            <option value="1">مفعّل</option>
                                            <option value="0">معطّل</option>
                                        </select>
                                    @elseif ($def->type->value === 'enum')
                                        <select wire:model="values.{{ $formKey }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" dir="ltr">
                                            @foreach ($def->options as $option)
                                                <option value="{{ $option }}">{{ $option }}</option>
                                            @endforeach
                                        </select>
                                    @elseif (in_array($def->type->value, ['text', 'template'], true))
                                        <textarea wire:model="values.{{ $formKey }}" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                                    @else
                                        <input wire:model="values.{{ $formKey }}" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    @endif
                                    @if (isset($messages[$key]))
                                        <p class="text-sm text-rose-600">{{ $messages[$key] }}</p>
                                    @endif
                                    <div class="flex gap-2">
                                        <button type="button" wire:click="save('{{ $key }}')" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">حفظ</button>
                                        @if ($e->stored)
                                            <button type="button" wire:click="resetToDefault('{{ $key }}')" wire:confirm="إعادة «{{ $def->label }}» إلى القيمة الافتراضية؟" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">إعادة إلى الافتراضي</button>
                                        @endif
                                    </div>
                                </div>
                            @else
                                <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700" dir="ltr">
                                    @if ($def->type->value === 'boolean')
                                        {{ $e->value ? 'true' : 'false' }}
                                    @else
                                        {{ $e->value === null || $e->value === '' ? '—' : $e->value }}
                                    @endif
                                </div>
                                @if (! $def->readOnly && ! $e->envForced())
                                    <p class="mt-1 text-[11px] text-slate-400">تحتاج صلاحية {{ $def->permission->value }} للتعديل.</p>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
