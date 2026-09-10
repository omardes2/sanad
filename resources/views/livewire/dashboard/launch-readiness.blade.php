<div>
    <header class="mb-4">
        <h1 class="text-2xl font-bold text-slate-800">جاهزية الإطلاق V1</h1>
        <p class="mt-1 text-sm text-slate-500">
            تُقرأ هذه الصفحة لحظيًا من الإعدادات والبنية والصفوف الموجودة: <strong>لا تحفظ ولا تغيّر ولا تتصل بأي خدمة خارجية</strong>،
            ولا تعرض أي مفتاح ولا رمز ولا بصمة. مصدر البنود هو <span dir="ltr">LaunchGateRegistry</span> في الكود — لا يُقرأ أي ملف Markdown هنا.
        </p>
    </header>

    {{-- ─── Verdict ──────────────────────────────────────────────────────── --}}
    @php $blocked = count($blockers) > 0; @endphp
    <div @class([
            'mb-6 rounded-xl border px-4 py-3 text-sm',
            'border-rose-200 bg-rose-50 text-rose-900' => $blocked,
            'border-emerald-200 bg-emerald-50 text-emerald-900' => ! $blocked,
        ]) data-testid="verdict">
        @if ($blocked)
            <strong dir="ltr">V1 BLOCKED</strong> — {{ count($blockers) }} بندًا حاجزًا من أصل {{ $summary['required'] }} بندًا مطلوبًا.
        @else
            <strong dir="ltr">V1 NOT BLOCKED</strong> — لا يوجد بند حاجز بين {{ $summary['required'] }} بندًا مطلوبًا.
        @endif
        <span class="text-xs opacity-80">
            (جاهز: {{ $summary['ready'] }} · مؤجَّل بعد V1: {{ $summary['deferred'] }})
        </span>
    </div>

    {{-- ─── Blockers ─────────────────────────────────────────────────────── --}}
    <section class="mb-8" data-testid="section-blockers">
        <h2 class="mb-2 text-lg font-bold text-slate-800">البنود الحاجزة</h2>
        @if ($blockers === [])
            <p class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600" data-testid="no-blockers">
                لا يوجد بند حاجز حاليًا.
            </p>
        @else
            <ul class="space-y-2">
                @foreach ($blockers as $status)
                    <li class="rounded-xl border border-rose-200 bg-white px-4 py-3" data-testid="blocker-{{ $status->gate->key }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800" dir="ltr">{{ strtoupper(str_replace('_', ' ', $status->state->value)) }}</span>
                            <strong class="text-slate-800">{{ $status->gate->title }}</strong>
                            <span class="text-xs text-slate-500">— المسؤول: {{ $status->gate->owner->label() }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ $status->summary }}</p>
                        <p class="mt-1 text-xs text-slate-500">لماذا هو حاجز: {{ $status->gate->why }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ─── Required gates ───────────────────────────────────────────────── --}}
    <section class="mb-8" data-testid="section-gates">
        <h2 class="mb-2 text-lg font-bold text-slate-800">بنود V1 المطلوبة</h2>
        <div class="space-y-3">
            @foreach ($required as $status)
                <details class="rounded-xl border border-slate-200 bg-white" data-testid="gate-{{ $status->gate->key }}">
                    <summary class="flex cursor-pointer flex-wrap items-center gap-2 px-4 py-3">
                        <span @class([
                                'rounded px-2 py-0.5 text-xs font-bold',
                                'bg-emerald-100 text-emerald-800' => $status->state->tone() === 'emerald',
                                'bg-rose-100 text-rose-800' => $status->state->tone() === 'rose',
                                'bg-amber-100 text-amber-800' => $status->state->tone() === 'amber',
                                'bg-slate-100 text-slate-700' => $status->state->tone() === 'slate',
                            ]) dir="ltr" data-testid="gate-state-{{ $status->gate->key }}">{{ strtoupper(str_replace('_', ' ', $status->state->value)) }}</span>
                        <strong class="text-slate-800">{{ $status->gate->title }}</strong>
                        @unless ($status->blocksLaunch())
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500">ليس حاجزًا</span>
                        @endunless
                        <span class="text-xs text-slate-500">{{ $status->gate->owner->label() }}</span>
                    </summary>
                    <div class="border-t border-slate-100 px-4 py-3">
                        <p class="mb-2 text-sm text-slate-700">{{ $status->summary }}</p>
                        <p class="mb-3 text-xs text-slate-500">{{ $status->gate->why }}</p>
                        <dl class="grid gap-1 text-sm md:grid-cols-2">
                            @foreach ($status->details as $detail)
                                <div class="flex gap-2 rounded border border-slate-100 px-2 py-1">
                                    <dt class="shrink-0 text-slate-500">{{ $detail->label }}:</dt>
                                    <dd @class([
                                            'font-medium',
                                            'text-emerald-700' => $detail->tone === 'ok',
                                            'text-rose-700' => $detail->tone === 'bad',
                                            'text-amber-700' => $detail->tone === 'unknown',
                                            'text-slate-700' => $detail->tone === 'plain',
                                        ])>{{ $detail->value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </details>
            @endforeach
        </div>
    </section>

    {{-- ─── Deferred (declared, not required) ────────────────────────────── --}}
    <section class="mb-8" data-testid="section-deferred">
        <h2 class="mb-2 text-lg font-bold text-slate-800">بنود معلَنة وغير مطلوبة لـV1</h2>
        <p class="mb-2 text-xs text-slate-500">
            تُعرَض لأن تأجيلها قرار، والقرار المؤجَّل يجب أن يُرى لا أن يُنسى. <strong>لا يمكن لأيٍّ منها أن يحجب الإطلاق.</strong>
        </p>
        <div class="space-y-2">
            @foreach ($deferred as $status)
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-3" data-testid="deferred-{{ $status->gate->key }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600" dir="ltr">{{ strtoupper(str_replace('_', ' ', $status->state->value)) }}</span>
                        <strong class="text-slate-800">{{ $status->gate->title }}</strong>
                        <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500">ليس حاجزًا</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-600">{{ $status->summary }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ─── Operational warnings — NOT launch authority ──────────────────── --}}
    <section data-testid="section-warnings">
        <h2 class="mb-2 text-lg font-bold text-slate-800">تنبيهات تشغيلية</h2>
        <div class="mb-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="warnings-disclaimer">
            هذه أعداد خلال آخر {{ $warningWindowDays }} أيام، <strong>وليست بنود إطلاق</strong>. لا توجد عتبة رقمية معتمَدة تجعل أيًّا منها حاجزًا،
            ولن تصير كذلك إلا باعتماد صريح ينقلها إلى سجل البنود.
        </div>
        @if ($warnings === [])
            <p class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600" data-testid="no-warnings">
                لا توجد تنبيهات خلال آخر {{ $warningWindowDays }} أيام.
            </p>
        @else
            <ul class="space-y-2">
                @foreach ($warnings as $warning)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 bg-white px-4 py-3" data-testid="warning-{{ $warning['key'] }}">
                        <div>
                            <strong class="text-slate-800">{{ $warning['label'] }}</strong>
                            <p class="text-xs text-slate-500">{{ $warning['hint'] }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-amber-100 px-2 py-0.5 text-sm font-bold text-amber-800" dir="ltr">{{ $warning['count'] }}</span>
                            @if ($warning['route'] && Route::has($warning['route']))
                                <a href="{{ route($warning['route'], $warning['params']) }}" wire:navigate class="rounded-lg border border-slate-300 px-3 py-1 text-xs text-slate-700 hover:bg-slate-50">عرض</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
