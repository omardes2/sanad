<div>
    <header class="mb-4">
        <h1 class="text-2xl font-bold text-slate-800">نظرة عامة</h1>
        <p class="mt-1 text-sm text-slate-500">
            أرقام محدودة بنافذة زمنية — لا أعداد تراكمية: «كل الرسائل منذ البداية» رقم يكبر دائمًا ولا يُبنى عليه قرار.
        </p>
    </header>

    {{-- ─── Readiness strip ──────────────────────────────────────────────── --}}
    @if ($readiness !== null)
        <a href="{{ route('dashboard.launch') }}" wire:navigate
           @class([
                'mb-6 block rounded-xl border px-4 py-3 text-sm transition hover:opacity-90',
                'border-rose-200 bg-rose-50 text-rose-900' => $readiness['blockers'] > 0,
                'border-emerald-200 bg-emerald-50 text-emerald-900' => $readiness['blockers'] === 0,
            ]) data-testid="readiness-strip">
            @if ($readiness['blockers'] > 0)
                <strong dir="ltr">V1 BLOCKED</strong> — {{ $readiness['blockers'] }} بندًا حاجزًا
            @else
                <strong dir="ltr">V1 NOT BLOCKED</strong>
            @endif
            <span class="text-xs opacity-80">(جاهز {{ $readiness['ready'] }} من {{ $readiness['required'] }} بندًا مطلوبًا — اضغط للتفاصيل)</span>
        </a>
    @endif

    {{-- ─── Last 24h ─────────────────────────────────────────────────────── --}}
    <section class="mb-8" data-testid="today">
        <h2 class="mb-2 text-lg font-bold text-slate-800">آخر ٢٤ ساعة</h2>
        <div class="grid gap-3 md:grid-cols-4">
            @foreach ($today as $key => $card)
                <div class="rounded-2xl border border-slate-200 bg-white p-3" data-testid="card-{{ $key }}">
                    <p class="text-[11px] text-slate-500">{{ $card['label'] }}</p>
                    <p class="text-2xl font-bold text-slate-800" dir="ltr">{{ $card['value'] }}</p>
                    <p class="mt-1 text-[11px] text-slate-400">{{ $card['hint'] }}</p>
                    @if ($card['route'] && Route::has($card['route']))
                        <a href="{{ route($card['route'], $card['params']) }}" wire:navigate class="mt-1 inline-block text-xs text-emerald-700 hover:underline">عرض</a>
                    @endif
                </div>
            @endforeach
        </div>
        @unless ($showCosts)
            <p class="mt-2 text-xs text-slate-400" data-testid="costs-hidden">أعمدة التكلفة تحتاج صلاحية <span dir="ltr">usage.view_costs</span>.</p>
        @endunless
    </section>

    {{-- ─── Needs attention ──────────────────────────────────────────────── --}}
    @if ($warnings !== null)
        <section data-testid="attention">
            <h2 class="mb-2 text-lg font-bold text-slate-800">يحتاج انتباهًا</h2>
            <p class="mb-2 text-xs text-slate-500">
                أعداد خلال آخر {{ $warningWindowDays }} أيام. <strong>ليست بنود إطلاق</strong> ولا عتبة فيها تحجب الإصدار.
            </p>
            @if ($warnings === [])
                <p class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600" data-testid="nothing-to-do">
                    لا شيء يحتاج انتباهًا خلال آخر {{ $warningWindowDays }} أيام.
                </p>
            @else
                <ul class="space-y-2">
                    @foreach ($warnings as $warning)
                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 bg-white px-4 py-3" data-testid="attention-{{ $warning['key'] }}">
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
    @endif
</div>
