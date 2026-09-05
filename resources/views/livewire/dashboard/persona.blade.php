<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">شخصية سَنَد والـPrompts</h1>
        <p class="mt-1 text-sm text-slate-500">نص عادي فقط. لا يوجد أي قالب قابل للتنفيذ؛ العناصر المسموحة تُستبدل حرفيًا.</p>
    </header>

    @if ($notice)
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-slate-800">{{ $personaEffective->definition->label }}</h2>
            <div class="flex items-center gap-2 text-[11px]">
                <span class="rounded-full {{ $personaEffective->source === 'db' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }} px-2 py-0.5 font-medium">{{ $personaEffective->source === 'db' ? 'من قاعدة البيانات' : 'الافتراضي' }}</span>
                @if ($personaEffective->invalid)
                    <span class="rounded-full bg-rose-100 px-2 py-0.5 font-medium text-rose-800">القيمة المخزَّنة غير صالحة — يُستخدم الافتراضي</span>
                @endif
                <span class="text-slate-400">{{ $personaLength }} حرف</span>
            </div>
        </div>
        <p class="mb-3 text-xs text-slate-500">{{ $personaEffective->definition->description }}</p>
        <textarea wire:model.live.debounce.500ms="persona" rows="14" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm leading-relaxed"></textarea>
        @if ($personaError)
            <p class="mt-1 text-sm text-rose-600">{{ $personaError }}</p>
        @endif
        <div class="mt-3 flex gap-2">
            <button type="button" wire:click="savePersona" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">حفظ الشخصية</button>
            @if ($personaEffective->stored)
                <button type="button" wire:click="resetKey('ai.persona')" wire:confirm="إعادة الشخصية إلى النص الافتراضي؟" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">إعادة إلى الافتراضي</button>
            @endif
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-slate-800">{{ $temporalEffective->definition->label }}</h2>
            <div class="flex items-center gap-2 text-[11px]">
                <span class="rounded-full {{ $temporalEffective->source === 'db' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }} px-2 py-0.5 font-medium">{{ $temporalEffective->source === 'db' ? 'من قاعدة البيانات' : 'الافتراضي' }}</span>
                @if ($temporalEffective->invalid)
                    <span class="rounded-full bg-rose-100 px-2 py-0.5 font-medium text-rose-800">القيمة المخزَّنة غير صالحة — يُستخدم الافتراضي</span>
                @endif
            </div>
        </div>
        <p class="mb-3 text-xs text-slate-500">{{ $temporalEffective->definition->description }}</p>
        <textarea wire:model.live.debounce.500ms="temporal" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm leading-relaxed"></textarea>
        @if ($temporalError)
            <p class="mt-1 text-sm text-rose-600">{{ $temporalError }}</p>
        @endif
        <div class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
            <span class="text-[11px] text-slate-400">معاينة:</span>
            <div>{{ $temporalPreview }}</div>
        </div>
        <div class="mt-3 flex gap-2">
            <button type="button" wire:click="saveTemporal" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">حفظ القالب</button>
            @if ($temporalEffective->stored)
                <button type="button" wire:click="resetKey('prompts.temporal_context')" wire:confirm="إعادة القالب إلى النص الافتراضي؟" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">إعادة إلى الافتراضي</button>
            @endif
        </div>
    </section>
</div>
