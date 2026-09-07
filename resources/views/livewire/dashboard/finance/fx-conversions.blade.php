<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">تحويلات التقرير (Reporting Conversions)</h1>
            <p class="mt-1 text-sm text-slate-500">تحويل مجمَّد لكل (موضوع، هدف): يحمل مبلغه ومصدره والسعر المستخدم واتجاهه ولحظة تجميده. عملة التقرير الحالية <strong dir="ltr">{{ $reportingCurrency }}</strong>. تغيير عملة التقرير لا يعيد حساب أي صف هنا.</p>
        </div>
        <nav class="flex flex-wrap gap-2">
            <a href="{{ route('dashboard.finance.fx') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-fx">الأزواج وعملة التقرير</a>
            <a href="{{ route('dashboard.finance.fx.rates') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-rates">الأسعار</a>
            @if ($canAudit)
                <a href="{{ $auditUrl }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="link-audit-conversions">سجل التحويلات</a>
            @endif
        </nav>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="conversions-disclaimer">
        Explicit <code>fx_rate_id</code> only — no latest / nearest / fallback rate · the quote must be dated on the subject's policy date (payment received_at · refund refunded_at · reconciliation and adjustment period_end, UTC) · NATIVE is a display state, never a rate-1 conversion · frozen revisions are never recomputed · the subject itself is never modified
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    {{-- ─── Filters (URL, allowlisted) ───────────────────────────────────── --}}
    <section class="mb-4" data-testid="section-filters">
        <div class="grid gap-3 md:grid-cols-3">
            <label class="block text-sm"><span class="text-slate-600">نوع الموضوع</span>
                <select wire:model.live="type" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-type"><option value="">الكل</option>@foreach ($types as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></label>
            <label class="block text-sm"><span class="text-slate-600">العملة الهدف</span><input type="text" wire:model.live.debounce.400ms="target" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-target"></label>
        </div>
    </section>

    {{-- ─── Conversion scopes ────────────────────────────────────────────── --}}
    <section class="mb-8" data-testid="section-conversions">
        <h2 class="text-base font-bold text-slate-800">النطاقات — {{ $scopes->total() }} rows · page {{ $scopes->currentPage() }} of {{ max(1, $scopes->lastPage()) }}</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                    <th class="px-3 py-2 text-left">Scope</th><th class="px-3 py-2 text-left">Subject</th><th class="px-3 py-2 text-left">Target</th><th class="px-3 py-2 text-left">Current</th><th class="px-3 py-2 text-right">Source</th><th class="px-3 py-2 text-right">Converted</th><th class="px-3 py-2 text-left">Rate used</th><th class="px-3 py-2 text-left">Policy date (UTC)</th><th class="px-3 py-2 text-right">Revisions</th><th class="px-3 py-2 text-left">Detail</th>
                </tr></thead>
                <tbody>
                @forelse ($scopes as $scope)
                    @php($conversion = $scope->current_conversion_id ? ($current[$scope->current_conversion_id] ?? null) : null)
                    <tr class="border-t border-slate-100" data-testid="scope-{{ $scope->id }}">
                        <td class="px-3 py-2">{{ $scope->id }}</td>
                        <td class="px-3 py-2">{{ $scope->subject_type }} #{{ $scope->subject_id }}</td>
                        <td class="px-3 py-2">{{ $scope->target_currency }}</td>
                        <td class="px-3 py-2">{{ $conversion ? '#'.$conversion->id : 'NOT CONVERTED' }}</td>
                        <td class="px-3 py-2 text-right">{{ $conversion ? $conversion->sourceAmountAtScale().' '.$conversion->source_currency : '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $conversion ? $conversion->targetAmountAtScale().' '.$conversion->target_currency : '—' }}</td>
                        <td class="px-3 py-2">{{ $conversion ? '#'.$conversion->fx_rate_id.' · '.$conversion->rate_snapshot.' · '.$conversion->direction->value : '—' }}</td>
                        <td class="px-3 py-2">{{ $conversion ? $conversion->subject_date->utc()->toDateString() : '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $revisions[$scope->id]->revisions ?? 0 }}</td>
                        <td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.fx.conversions.show', $scope->id) }}">detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-3 py-3 text-center text-slate-500">لا تحويلات مطابقة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-2">{{ $scopes->links() }}</div>
    </section>

    {{-- ─── Convert one subject (explicit rate id) ───────────────────────── --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-conversion">
        <h2 class="text-base font-bold text-slate-800">تحويل موضوع للتقرير (Convert Subject)</h2>
        <p class="mb-3 text-xs text-slate-500">حمّل الموضوع أولًا لترى عملته وتاريخ سياسته وحالته والمؤشر الحالي، ثم اختر <code>fx_rate_id</code> من الأسعار المسجَّلة لذلك التاريخ بالضبط. لا يُختار سعر تلقائيًا، ولا يُقبل سعر بتاريخ آخر، ولا يُحوَّل موضوع بعملة الهدف نفسها.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'conversion'])
        <form wire:submit="convert" class="grid gap-2 md:grid-cols-3">
            <label class="text-sm">مفتاح المحاولة<input type="text" wire:model="convKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="conversion-attempt-key"></label>
            <label class="text-sm">نوع الموضوع<select wire:model.live="convSubjectType" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="conv-type">@foreach ($types as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></label>
            <label class="text-sm">معرّف الموضوع<input type="text" wire:model.live="convSubjectId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="conv-subject-id"></label>
            <label class="text-sm">العملة الهدف<input type="text" wire:model.live="convTarget" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="conv-target"></label>
            <div class="md:col-span-2 flex items-end">
                <button type="button" wire:click="loadSubject" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" data-testid="conv-load">LOAD SUBJECT</button>
            </div>

            @if ($subject)
                <div class="md:col-span-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm" dir="ltr" data-testid="conv-subject">
                    <p><strong>{{ $subject['type'] }} #{{ $subject['id'] }}</strong> · {{ $subject['amount'] }} {{ $subject['source_currency'] }} · policy date {{ $subject['policy_date'] }} (UTC) · target {{ $subject['target'] }} · status <strong data-testid="conv-status">{{ $subject['status'] }}</strong>
                        @if ($subject['current']) · current conversion #{{ $subject['current']['id'] }} = {{ $subject['current']['target_amount'] }} {{ $subject['target'] }} via rate #{{ $subject['current']['fx_rate_id'] }} ({{ $subject['current']['direction'] }})@endif
                        @if ($subject['scope_id']) · <a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.fx.conversions.show', $subject['scope_id']) }}">scope #{{ $subject['scope_id'] }}</a>@endif
                    </p>
                    @if ($subject['status'] === 'NATIVE')
                        <p class="mt-1 text-slate-600">NATIVE — same currency as the target: shown as-is, never converted and never given a rate-1 conversion.</p>
                    @elseif ($subject['quotes'] === [])
                        <p class="mt-1 text-rose-700" data-testid="conv-no-quotes">No quote recorded for {{ $subject['source_currency'] }}/{{ $subject['target'] }} on {{ $subject['policy_date'] }}. Record one for exactly that date — no nearest or latest rate is used.</p>
                    @else
                        <table class="mt-2 min-w-full text-xs">
                            <thead class="text-slate-500"><tr><th class="px-2 py-1 text-left">Pick</th><th class="px-2 py-1 text-left">fx_rate_id</th><th class="px-2 py-1 text-left">Pair</th><th class="px-2 py-1 text-right">Rate</th><th class="px-2 py-1 text-left">Date</th><th class="px-2 py-1 text-left">Evidence</th></tr></thead>
                            <tbody>
                            @foreach ($subject['quotes'] as $quote)
                                <tr data-testid="conv-quote-{{ $quote['id'] }}">
                                    <td class="px-2 py-1"><input type="radio" wire:model.live="convRateId" value="{{ $quote['id'] }}" data-testid="conv-pick-{{ $quote['id'] }}"></td>
                                    <td class="px-2 py-1">#{{ $quote['id'] }}</td><td class="px-2 py-1">{{ $quote['pair'] }}</td><td class="px-2 py-1 text-right">{{ $quote['rate'] }}</td><td class="px-2 py-1">{{ $quote['date'] }}</td><td class="px-2 py-1">{{ $quote['evidence'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>

                <label class="text-sm">fx_rate_id (صريح)<input type="text" wire:model="convRateId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="conv-rate-id"></label>
                <label class="text-sm">التحويل الحالي المتوقع<input type="text" wire:model="expectedId" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="conv-expected"></label>
                <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="convReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>

                <div class="md:col-span-3">
                    @if ($confirming)
                        <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" dir="ltr" data-testid="conv-confirm">Freeze {{ $subject['type'] }} #{{ $subject['id'] }} into {{ $subject['target'] }} using rate #{{ $convRateId ?: '?' }} on {{ $subject['policy_date'] }} · expected current conversion: {{ $expectedId === '' ? 'none' : '#'.$expectedId }}</p>
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700 disabled:opacity-50" data-testid="conv-submit">تأكيد التحويل</button>
                        <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">إلغاء</button>
                    @else
                        <button type="button" wire:click="openConfirm" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700" data-testid="conv-open">تحويل…</button>
                    @endif
                </div>
            @endif
        </form>
    </section>
</div>
