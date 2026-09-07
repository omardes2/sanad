<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">نطاق السعر #{{ $scope->id }} — <span dir="ltr">{{ $pair->pair_key }}</span> @ <span dir="ltr">{{ $scope->rate_date->format('Y-m-d') }}</span></h1>
            <p class="mt-1 text-sm text-slate-500">الاتجاه الرسمي: <span dir="ltr">1 {{ $pair->base_currency }} = rate × {{ $pair->quote_currency }}</span> · المراجعة الحالية: <span dir="ltr" data-testid="current-revision">{{ $scope->current_rate_id ? '#'.$scope->current_rate_id : 'NO QUOTE' }}</span> · الرمز المعروض: <code dir="ltr" data-testid="scope-token">{{ $scopeToken }}</code> · revision {{ $scope->version }}</p>
        </div>
        <nav class="flex flex-wrap gap-2">
            <a href="{{ route('dashboard.finance.fx.rates', ['pair' => $pair->pair_key]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">كل الأسعار</a>
            @if ($canAudit)
                <a href="{{ $auditUrl }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-audit">سجل هذا النطاق</a>
            @endif
        </nav>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="scope-disclaimer">
        Append-only revisions · a correction supersedes the pointer, it never edits a row · conversions already frozen on a superseded revision are <strong>never recomputed</strong> — correct each one explicitly on its own scope · a quote belongs to its date only
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    {{-- ─── Revision history (append-only) ───────────────────────────────── --}}
    <section class="mb-8" data-testid="section-revisions">
        <h2 class="text-base font-bold text-slate-800">المراجعات — {{ $revisions->count() }}</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                    <th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-right">Rate</th><th class="px-3 py-2 text-left">Supersedes</th><th class="px-3 py-2 text-left">Source</th><th class="px-3 py-2 text-left">Evidence</th><th class="px-3 py-2 text-left">Reason</th><th class="px-3 py-2 text-left">Actor</th><th class="px-3 py-2 text-left">Recorded (UTC)</th><th class="px-3 py-2 text-left">State</th>
                </tr></thead>
                <tbody>
                @forelse ($revisions as $revision)
                    <tr class="border-t border-slate-100 {{ $revision->id === $scope->current_rate_id ? 'bg-emerald-50/50' : '' }}" data-testid="revision-{{ $revision->id }}">
                        <td class="px-3 py-2">{{ $revision->id }}</td>
                        <td class="px-3 py-2 text-right">{{ $revision->rate }}</td>
                        <td class="px-3 py-2">{{ $revision->supersedes_id ? '#'.$revision->supersedes_id : '—' }}</td>
                        <td class="px-3 py-2">{{ $revision->source }}</td>
                        <td class="px-3 py-2 text-xs">{{ $revision->evidence_ref }}</td>
                        <td class="px-3 py-2 text-xs">{{ $revision->reason_code ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ $revision->recorded_by_ref }}</td>
                        <td class="px-3 py-2">{{ $revision->created_at->utc()->format('Y-m-d H:i:s') }}</td>
                        <td class="px-3 py-2 font-semibold">{{ $revision->id === $scope->current_rate_id ? 'CURRENT' : 'SUPERSEDED' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-3 text-center text-slate-500">لا مراجعات بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ─── Frozen conversions using these revisions ─────────────────────── --}}
    <section class="mb-8" data-testid="section-frozen">
        <h2 class="text-base font-bold text-slate-800">تحويلات مجمَّدة على مراجعات هذا النطاق — {{ $frozen->count() }}</h2>
        <p class="mb-2 text-xs text-slate-500">تُعرض كما جُمِّدت. تصحيح السعر لا يغيّر أيًّا منها؛ لتصحيح تحويل افتح نطاقه واختر المراجعة الحالية صراحةً.</p>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                    <th class="px-3 py-2 text-left">Conversion</th><th class="px-3 py-2 text-left">Subject</th><th class="px-3 py-2 text-right">Source</th><th class="px-3 py-2 text-right">Target</th><th class="px-3 py-2 text-left">Rate used</th><th class="px-3 py-2 text-left">Direction</th><th class="px-3 py-2 text-left">Scope</th>
                </tr></thead>
                <tbody>
                @forelse ($frozen as $conversion)
                    <tr class="border-t border-slate-100" data-testid="frozen-{{ $conversion->id }}">
                        <td class="px-3 py-2">#{{ $conversion->id }}</td>
                        <td class="px-3 py-2">{{ $conversion->subject_type }} #{{ $conversion->subject_id }}</td>
                        <td class="px-3 py-2 text-right">{{ $conversion->sourceAmountAtScale() }} {{ $conversion->source_currency }}</td>
                        <td class="px-3 py-2 text-right">{{ $conversion->targetAmountAtScale() }} {{ $conversion->target_currency }}</td>
                        <td class="px-3 py-2">#{{ $conversion->fx_rate_id }} · {{ $conversion->rate_snapshot }}</td>
                        <td class="px-3 py-2">{{ $conversion->direction->value }}</td>
                        <td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.fx.conversions.show', $conversion->scope_id) }}">scope #{{ $conversion->scope_id }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-3 text-center text-slate-500">لا تحويلات على هذه المراجعات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ─── Correct / supersede ──────────────────────────────────────────── --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-rate">
        <h2 class="text-base font-bold text-slate-800">تصحيح السعر (Correct / Supersede)</h2>
        <p class="mb-3 text-xs text-slate-500">تُلحق مراجعة جديدة تحلّ محل <span dir="ltr">{{ $scope->current_rate_id ? '#'.$scope->current_rate_id : 'none' }}</span> لنفس الزوج ونفس التاريخ. الصفحة ترسل هذا المؤشر كما عُرض؛ إن سجّل طلب آخر مراجعة قبلك يُرفض الطلب كـ<span dir="ltr">STATE CHANGED</span> ولا يُكتب شيء.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'rate'])
        <form wire:submit="correctRate" class="grid gap-2 md:grid-cols-3">
            <label class="text-sm">مفتاح المحاولة<input type="text" wire:model="rateKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="rate-attempt-key"></label>
            <label class="text-sm">المراجعة الحالية المتوقعة<input type="text" wire:model="expectedId" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="rate-expected"></label>
            <label class="text-sm">السعر الجديد (1 {{ $pair->base_currency }} = ? {{ $pair->quote_currency }})<input type="text" wire:model="rateValue" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="rate-value"></label>
            <label class="text-sm">مرجع الدليل<input type="text" wire:model="rateEvidence" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="rate-evidence"></label>
            <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="rateReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <div class="md:col-span-3">
                @if ($confirming)
                    <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" dir="ltr" data-testid="rate-confirm">New revision: 1 {{ $pair->base_currency }} = {{ $rateValue ?: '?' }} {{ $pair->quote_currency }} on {{ $scope->rate_date->format('Y-m-d') }} · supersedes {{ $scope->current_rate_id ? '#'.$scope->current_rate_id : 'none' }} · frozen conversions unchanged</p>
                    <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50" data-testid="rate-submit">تأكيد التصحيح</button>
                    <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">إلغاء</button>
                @else
                    <button type="button" wire:click="openConfirm" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700" data-testid="rate-open">تصحيح السعر…</button>
                @endif
            </div>
        </form>
    </section>
</div>
