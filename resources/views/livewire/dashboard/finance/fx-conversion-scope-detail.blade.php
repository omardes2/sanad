<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">نطاق التحويل #{{ $scope->id }} — <span dir="ltr">{{ $scope->subject_type }} #{{ $scope->subject_id }} → {{ $scope->target_currency }}</span></h1>
            <p class="mt-1 text-sm text-slate-500">الغرض <span dir="ltr">{{ $scope->purpose }}</span> · الحالة <strong dir="ltr" data-testid="status">{{ $status }}</strong> · المؤشر الحالي <span dir="ltr" data-testid="current-conversion">{{ $scope->current_conversion_id ? '#'.$scope->current_conversion_id : 'none' }}</span> · الرمز المعروض <code dir="ltr" data-testid="scope-token">{{ $scopeToken }}</code> · revision {{ $scope->version }}@if ($policyDate) · تاريخ السياسة <span dir="ltr">{{ $policyDate }}</span> (UTC)@endif</p>
        </div>
        <nav class="flex flex-wrap gap-2">
            <a href="{{ route('dashboard.finance.fx.conversions') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">كل التحويلات</a>
            @if ($canAudit)
                <a href="{{ $auditUrl }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-audit">سجل هذا الموضوع</a>
            @endif
        </nav>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="conversion-disclaimer">
        Every revision keeps the exact rate row, its snapshot and its direction as frozen · a correction appends a new revision, it never edits or recomputes an old one · the rate must be dated on the subject's policy date · the subject itself is never modified
    </div>

    @if ($subjectMissing)
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800" data-testid="subject-missing">الموضوع غير موجود؛ يُعرض السجل المجمَّد فقط.</div>
    @endif

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    {{-- ─── Correction history (append-only) ─────────────────────────────── --}}
    <section class="mb-8" data-testid="section-revisions">
        <h2 class="text-base font-bold text-slate-800">المراجعات — {{ $revisions->count() }}</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                    <th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-right">Source</th><th class="px-3 py-2 text-right">Target</th><th class="px-3 py-2 text-left">Rate used</th><th class="px-3 py-2 text-left">Rate date</th><th class="px-3 py-2 text-left">Direction</th><th class="px-3 py-2 text-left">Supersedes</th><th class="px-3 py-2 text-left">Reason</th><th class="px-3 py-2 text-left">Actor</th><th class="px-3 py-2 text-left">Frozen at (UTC)</th><th class="px-3 py-2 text-left">State</th>
                </tr></thead>
                <tbody>
                @forelse ($revisions as $revision)
                    <tr class="border-t border-slate-100 {{ $revision->id === $scope->current_conversion_id ? 'bg-emerald-50/50' : '' }}" data-testid="revision-{{ $revision->id }}">
                        <td class="px-3 py-2">{{ $revision->id }}</td>
                        <td class="px-3 py-2 text-right">{{ $revision->sourceAmountAtScale() }} {{ $revision->source_currency }}</td>
                        <td class="px-3 py-2 text-right">{{ $revision->targetAmountAtScale() }} {{ $revision->target_currency }}</td>
                        <td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.fx.rates.show', $revision->rate?->scope_id ?? 0) }}">#{{ $revision->fx_rate_id }}</a> · {{ $revision->rate_snapshot }}</td>
                        <td class="px-3 py-2">{{ $revision->fx_rate_date->format('Y-m-d') }}</td>
                        <td class="px-3 py-2">{{ $revision->direction->value }}</td>
                        <td class="px-3 py-2">{{ $revision->supersedes_id ? '#'.$revision->supersedes_id : '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ $revision->reason_code ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ $revision->actor_ref }}</td>
                        <td class="px-3 py-2">{{ $revision->created_at->utc()->format('Y-m-d H:i:s') }}</td>
                        <td class="px-3 py-2 font-semibold">{{ $revision->id === $scope->current_conversion_id ? 'CURRENT' : 'SUPERSEDED' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-3 py-3 text-center text-slate-500">لا مراجعات بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ─── Correct the conversion ───────────────────────────────────────── --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-conversion">
        <h2 class="text-base font-bold text-slate-800">تصحيح التحويل (Correct Conversion)</h2>
        <p class="mb-3 text-xs text-slate-500">مراجعة جديدة تحلّ محل <span dir="ltr">{{ $scope->current_conversion_id ? '#'.$scope->current_conversion_id : 'none' }}</span> بسعر تختاره صراحةً من أسعار تاريخ السياسة. الصفحة ترسل المؤشر كما عُرض؛ تغيّره ⇒ <span dir="ltr">STATE CHANGED</span> بلا كتابة.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'conversion'])
        <form wire:submit="correctConversion" class="grid gap-2 md:grid-cols-3">
            <label class="text-sm">مفتاح المحاولة<input type="text" wire:model="convKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="conversion-attempt-key"></label>
            <label class="text-sm">التحويل الحالي المتوقع<input type="text" wire:model="expectedId" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="conv-expected"></label>
            <label class="text-sm">fx_rate_id (صريح)<input type="text" wire:model="convRateId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="conv-rate-id"></label>
            <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="convReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>

            @if ($confirming)
                <div class="md:col-span-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm" dir="ltr" data-testid="conv-confirm">
                    @if ($quotes === [])
                        <p class="text-rose-700" data-testid="conv-no-quotes">No current quote for {{ $sourceCurrency ?? '?' }}/{{ $scope->target_currency }} on {{ $policyDate ?? '?' }} — record one for exactly that date first.</p>
                    @else
                        <p class="mb-2 text-amber-900">Quotes recorded for {{ $policyDate }} (current revisions only) — pick one; nothing is selected for you.</p>
                        <table class="min-w-full text-xs">
                            <thead class="text-slate-500"><tr><th class="px-2 py-1 text-left">Pick</th><th class="px-2 py-1 text-left">fx_rate_id</th><th class="px-2 py-1 text-left">Pair</th><th class="px-2 py-1 text-right">Rate</th><th class="px-2 py-1 text-left">Evidence</th></tr></thead>
                            <tbody>
                            @foreach ($quotes as $quote)
                                <tr data-testid="conv-quote-{{ $quote->id }}">
                                    <td class="px-2 py-1"><input type="radio" wire:model.live="convRateId" value="{{ $quote->id }}" data-testid="conv-pick-{{ $quote->id }}"></td>
                                    <td class="px-2 py-1">#{{ $quote->id }}</td><td class="px-2 py-1">{{ $quote->base_currency }}/{{ $quote->quote_currency }}</td><td class="px-2 py-1 text-right">{{ $quote->rate }}</td><td class="px-2 py-1">{{ $quote->evidence_ref }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                    <div class="mt-3">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700 disabled:opacity-50" data-testid="conv-submit">تأكيد التصحيح</button>
                        <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">إلغاء</button>
                    </div>
                </div>
            @else
                <div class="md:col-span-3"><button type="button" wire:click="openConfirm" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700" data-testid="conv-open">تصحيح التحويل…</button></div>
            @endif
        </form>
    </section>
</div>
