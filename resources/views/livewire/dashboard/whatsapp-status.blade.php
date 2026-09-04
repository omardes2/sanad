<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">حالة تكامل واتساب</h1>
        <p class="mt-1 text-sm text-slate-500">
            عرض تشغيلي فقط — لا تُعرض أي رموز أو أسرار، فقط ما إذا كانت الإعدادات موجودة.
        </p>
    </header>

    {{-- Master switch --}}
    <div class="mb-6 flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div>
            <p class="text-sm text-slate-500">حالة التكامل</p>
            <p class="mt-1 text-lg font-bold {{ $enabled ? 'text-emerald-700' : 'text-slate-500' }}">
                {{ $enabled ? 'مفعّل' : 'غير مفعّل' }}
            </p>
        </div>
        <span @class([
            'inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium',
            'bg-emerald-50 text-emerald-700' => $enabled,
            'bg-slate-100 text-slate-600' => ! $enabled,
        ])>
            <span @class(['h-2 w-2 rounded-full', 'bg-emerald-500' => $enabled, 'bg-slate-400' => ! $enabled])></span>
            {{ $enabled ? 'ON' : 'OFF' }}
        </span>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Config checklist (presence only) --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-sm font-bold uppercase tracking-wider text-slate-500">الإعدادات المطلوبة</h2>
            <ul class="space-y-3">
                @foreach ($checklist as $label => $present)
                    <li class="flex items-center justify-between">
                        <span class="text-slate-700">{{ $label }}</span>
                        @include('livewire.partials.presence-badge', ['present' => $present])
                    </li>
                @endforeach
            </ul>

            <div class="mt-5 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">جاهز للإرسال</span>
                    @include('livewire.partials.presence-badge', ['present' => $canSend])
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">جاهز للاستقبال</span>
                    @include('livewire.partials.presence-badge', ['present' => $canReceive])
                </div>
            </div>
        </section>

        {{-- Infra: Horizon + Queue + Redis --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-sm font-bold uppercase tracking-wider text-slate-500">Horizon والطوابير</h2>

            @php
                $horizonLabel = ['running' => 'يعمل', 'inactive' => 'متوقّف', 'unavailable' => 'غير متاح'][$horizon] ?? $horizon;
                $horizonClass = [
                    'running' => 'bg-emerald-50 text-emerald-700',
                    'inactive' => 'bg-amber-50 text-amber-700',
                    'unavailable' => 'bg-slate-100 text-slate-600',
                ][$horizon] ?? 'bg-slate-100 text-slate-600';
            @endphp

            <ul class="space-y-3">
                <li class="flex items-center justify-between">
                    <span class="text-slate-700">Horizon</span>
                    <span class="rounded-full px-3 py-1 text-sm font-medium {{ $horizonClass }}">{{ $horizonLabel }}</span>
                </li>
                <li class="flex items-center justify-between">
                    <span class="text-slate-700">Redis</span>
                    @include('livewire.partials.status-badge', ['up' => $redisUp])
                </li>
            </ul>

            <h3 class="mt-5 mb-2 border-t border-slate-100 pt-4 text-xs font-bold uppercase tracking-wider text-slate-400">مهام معلّقة في الطوابير</h3>
            <ul class="space-y-2 text-sm">
                @foreach ($queues as $name => $size)
                    <li class="flex items-center justify-between">
                        <span class="font-mono text-slate-600">{{ $name }}</span>
                        <span class="rounded-md bg-slate-100 px-2 py-0.5 font-medium text-slate-700">
                            {{ $size === null ? 'غير متاح' : number_format((int) $size) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</div>
