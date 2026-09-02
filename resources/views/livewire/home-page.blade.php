<div class="flex min-h-screen flex-col items-center justify-center gap-8 px-6 py-16">

    {{-- Development notice --}}
    <div class="w-full max-w-2xl rounded-xl border border-amber-300 bg-amber-50 px-5 py-3 text-center text-sm font-medium text-amber-800">
        🚧 هذا المشروع قيد التطوير — Sprint 0 (الأساس). الميزات لم تُفعَّل بعد.
    </div>

    {{-- Brand --}}
    <header class="flex flex-col items-center gap-3 text-center">
        <h1 class="text-6xl font-extrabold tracking-tight text-emerald-700">سَنَد</h1>
        <p class="text-2xl font-semibold tracking-[0.35em] text-slate-400">SANAD</p>
        <p class="mt-2 max-w-md text-lg text-slate-600">
            مساعدك الذكي الذي يفهم، يتذكّر وينفّذ.
        </p>
    </header>

    {{-- System status --}}
    <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-center text-sm font-bold uppercase tracking-wider text-slate-500">
            حالة النظام
        </h2>

        <ul class="space-y-3">
            <li class="flex items-center justify-between">
                <span class="text-slate-700">التطبيق</span>
                <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    {{ $appName }} · {{ $environment }}
                </span>
            </li>

            <li class="flex items-center justify-between">
                <span class="text-slate-700">PostgreSQL</span>
                @include('livewire.partials.status-badge', ['up' => $services['postgres']])
            </li>

            <li class="flex items-center justify-between">
                <span class="text-slate-700">Redis</span>
                @include('livewire.partials.status-badge', ['up' => $services['redis']])
            </li>
        </ul>
    </section>

    <footer class="text-center text-xs text-slate-400">
        سَنَد | SANAD &copy; {{ now()->year }}
    </footer>
</div>
