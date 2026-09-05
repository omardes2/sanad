<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">Cutover — مصدر الكتالوج ووضع التوجيه والمزوّد الأساسي</h1>
        <p class="mt-1 text-sm text-slate-500">كل خطوة تغيّر شيئًا واحدًا فقط: معاينة (جاهزية + محاكاة بالموجّه الحقيقي) ← كتابة <code dir="ltr">provider:model</code> الناتج للتأكيد ← تطبيق يعيد فحص الحالة التي رأيتها. لا شيء هنا يعمل تلقائيًا.</p>
    </header>

    @if ($resolution->degraded())
        <div class="mb-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <strong>DEGRADED / ENV FALLBACK</strong> — وضع التوجيه <code dir="ltr">db</code> لكن لا مزوّد أساسي مفعّل ({{ $resolution->degradedReason }})؛ يُستخدم <code dir="ltr">AI_PROVIDER={{ $envProvider }}</code> للطوارئ. الوضع المخزَّن لم يُغيَّر تلقائيًا. عيّن مزوّدًا أساسيًا أو ارجع إلى env.
        </div>
    @endif
    @if ($notice)
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    <section class="mb-6 grid gap-3 md:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs text-slate-500">مصدر الكتالوج</p>
            <p class="mt-1 text-lg font-bold" dir="ltr">{{ $catalogMode }} <span class="text-sm font-normal text-slate-500">(الفعّال: {{ $catalogActive }})</span></p>
            @if ($catalogEnvForced)<p class="text-[11px] text-amber-700">مُجبَر من البيئة (AI_CATALOG_SOURCE)</p>@endif
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs text-slate-500">وضع التوجيه</p>
            <p class="mt-1 text-lg font-bold" dir="ltr">{{ $resolution->mode }} <span class="text-sm font-normal text-slate-500">→ {{ $resolution->provider }} ({{ $resolution->source }})</span></p>
            @if ($modeEnvForced)<p class="text-[11px] text-amber-700">مُجبَر من البيئة (AI_ROUTING_MODE)</p>@endif
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="text-xs text-slate-500">المزوّد الأساسي (is_primary)</p>
            <p class="mt-1 text-lg font-bold" dir="ltr">{{ $primary?->key ?? '—' }} @if ($primary && ! $primary->is_enabled)<span class="text-sm text-rose-700">(معطّل)</span>@endif</p>
            <p class="text-[11px] text-slate-400">AI_PROVIDER={{ $envProvider }}</p>
        </div>
    </section>

    {{-- Stage B --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-slate-700">Stage B — محاكاة كتالوج قاعدة البيانات (للقراءة فقط)</h2>
            <button type="button" wire:click="whatIf" class="rounded-lg border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50">شغّل المحاكاة</button>
        </div>
        @if ($previews['what_if'] ?? null)
            @include('livewire.dashboard.ai._cutover_preview', ['p' => $previews['what_if'], 'kind' => 'what_if', 'readOnly' => true])
        @endif
    </section>

    {{-- Stage C: catalog source --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-slate-700">Stage C — تغيير مصدر الكتالوج</h2>
        <p class="mt-1 text-[11px] text-slate-500">الانتقال إلى database يُسمح به فقط إذا بقي المسار (provider:model) كما هو؛ تغيير المسار نفسه عملية مستقلة لاحقًا.</p>
        <div class="mt-2 flex flex-wrap items-end gap-2">
            <label class="block text-sm"><span class="text-slate-600">الهدف</span>
                <select wire:model="catalogTarget" class="mt-1 rounded-lg border-slate-300 text-sm" dir="ltr"><option value="">—</option><option value="config">config</option><option value="database">database</option></select></label>
            <button type="button" wire:click="previewCatalog" class="rounded-lg border border-emerald-600 px-3 py-1.5 text-sm text-emerald-700 hover:bg-emerald-50">معاينة</button>
        </div>
        @include('livewire.dashboard.ai._cutover_problems', ['kind' => 'catalog_source'])
        @if ($previews['catalog_source'] ?? null)
            @include('livewire.dashboard.ai._cutover_preview', ['p' => $previews['catalog_source'], 'kind' => 'catalog_source', 'readOnly' => false, 'applyMethod' => 'applyCatalog'])
        @endif
    </section>

    {{-- Routing mode --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-slate-700">وضع التوجيه (env ↔ db)</h2>
        <p class="mt-1 text-[11px] text-slate-500">env → db يتطلب مزوّدًا أساسيًا مفعّلًا وكتالوج قاعدة البيانات فعّالًا ونفس المسار قبل وبعد. db → env هو الرجوع.</p>
        <div class="mt-2 flex flex-wrap items-end gap-2">
            <label class="block text-sm"><span class="text-slate-600">الهدف</span>
                <select wire:model="modeTarget" class="mt-1 rounded-lg border-slate-300 text-sm" dir="ltr"><option value="">—</option><option value="env">env</option><option value="db">db</option></select></label>
            <button type="button" wire:click="previewMode" class="rounded-lg border border-emerald-600 px-3 py-1.5 text-sm text-emerald-700 hover:bg-emerald-50">معاينة</button>
        </div>
        @include('livewire.dashboard.ai._cutover_problems', ['kind' => 'routing_mode'])
        @if ($previews['routing_mode'] ?? null)
            @include('livewire.dashboard.ai._cutover_preview', ['p' => $previews['routing_mode'], 'kind' => 'routing_mode', 'readOnly' => false, 'applyMethod' => 'applyMode'])
        @endif
    </section>

    {{-- Primary --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-slate-700">المزوّد الأساسي (is_primary)</h2>
        <p class="mt-1 text-[11px] text-slate-500">الطريقة الوحيدة لتغيير المزوّد الفعلي، بعد أن يصبح الوضع db. في وضع env لا أثر على التشغيل حتى التبديل.</p>
        <div class="mt-2 flex flex-wrap items-end gap-2">
            <label class="block text-sm"><span class="text-slate-600">المزوّد</span>
                <select wire:model="primaryTarget" class="mt-1 rounded-lg border-slate-300 text-sm" dir="ltr"><option value="">—</option>@foreach ($providers as $prov)<option value="{{ $prov->id }}">{{ $prov->key }}@if ($prov->is_primary) (primary)@endif</option>@endforeach</select></label>
            <button type="button" wire:click="previewPrimary" class="rounded-lg border border-emerald-600 px-3 py-1.5 text-sm text-emerald-700 hover:bg-emerald-50">معاينة</button>
        </div>
        @include('livewire.dashboard.ai._cutover_problems', ['kind' => 'primary'])
        @if ($previews['primary'] ?? null)
            @include('livewire.dashboard.ai._cutover_preview', ['p' => $previews['primary'], 'kind' => 'primary', 'readOnly' => false, 'applyMethod' => 'applyPrimary'])
        @endif
    </section>
</div>
