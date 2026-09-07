<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">إقفال الفترة (Period Close)</h1>
            <p class="mt-1 text-sm text-slate-500">إقفال شهر تقويمي <span dir="ltr">UTC</span> بعملة التقرير <strong dir="ltr">{{ $reportingCurrency }}</strong>: لقطة موقَّعة لكل مدخل (<span dir="ltr">input hash</span>) لا تُعدَّل أبدًا؛ إعادة الفتح سجل جديد لا يمسّ الإقفال القديم. <strong>Reconciled Cash Contribution</strong> مقياس داخلي على أساس النقد — ليس ربحًا إجماليًا ولا هامشًا ولا إيرادًا.</p>
        </div>
        @if ($scope !== null && $canAudit)
            <a href="{{ route('dashboard.audit', ['subject_type' => 'FinancePeriodCloseScope', 'subject_id' => $scope->id]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="audit-link">سجل التدقيق — scope #{{ $scope->id }}</a>
        @endif
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="close-disclaimer">
        Cash-basis internal metric · Reconciled Cash Contribution = Net Cash After Gateway Fees − Reconciled Service Cost · unknown fees block the close (never zero) · NO PRODUCER is not completeness · Gross Profit / Margin / Revenue Recognition: <strong>NOT AVAILABLE</strong>
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    {{-- ─── Month + on-demand preflight ──────────────────────────────────── --}}
    <section class="mb-6" data-testid="section-preflight">
        <div class="mb-3 grid gap-3 md:grid-cols-4">
            <label class="block text-sm"><span class="text-slate-600">الشهر (UTC)</span><input type="month" wire:model.live="month" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-month"></label>
            <div class="flex items-end gap-2">
                <button type="button" wire:click="runPreflight" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900" data-testid="run-preflight">RUN PREFLIGHT</button>
                @if ($preview)
                    <button type="button" wire:click="clearPreview" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" data-testid="clear-preview">إخفاء</button>
                @endif
            </div>
        </div>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'preflight'])

        @if (! $preview)
            <p class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600" data-testid="preflight-idle">
                <strong dir="ltr">PREFLIGHT: NOT RUN</strong> —
                لا يُشغَّل الـpreflight عند فتح الصفحة. اضغط <span dir="ltr">RUN PREFLIGHT</span> لتقييم <span dir="ltr">{{ $month }}</span> الآن. النتيجة معاينة إعلامية للحظتها فقط؛ الخدمة تعيد التقييم بنفسها عند الإقفال وهي المرجع الوحيد.
            </p>
        @else
            <h2 class="text-lg font-bold text-slate-800">PREVIEW — {{ $preview['month'] }} ({{ $preview['currency'] }})</h2>
            <p class="mb-2 text-xs text-slate-500" dir="ltr" data-testid="preview-meta">
                INFORMATIONAL PREVIEW taken at {{ $preview['at'] }} UTC · input hash {{ substr($preview['hash'], 0, 16) }}… · {{ $preview['can_close'] ? 'READY TO CLOSE (as of this preview)' : 'BLOCKED (as of this preview)' }} · the service re-evaluates the month at close and decides
            </p>
            <x-finance.banners testid="preflight-banners"
                :blocking="$preview['blocking']"
                :actions="$blockerLinks"
                :info="array_values(array_map(fn ($c) => $c['code'].' ('.$c['detail'].')', array_filter($preview['conditions'], fn ($c) => ! $c['blocking'])))" />
            <div class="mb-3 grid gap-3 md:grid-cols-4">
                @foreach (['gross_cash_collected' => 'Gross Cash Collected', 'refunds' => 'Refunds', 'net_cash' => 'Net Cash', 'gateway_fees' => 'Gateway Fees', 'net_cash_after_gateway_fees' => 'Net Cash After Gateway Fees', 'reconciled_service_cost' => 'Reconciled Service Cost', 'reconciled_cash_contribution' => 'Reconciled Cash Contribution'] as $key => $label)
                    <div class="rounded-2xl border border-slate-200 bg-white p-3" data-testid="metric-{{ $key }}">
                        <p class="text-[11px] text-slate-500">{{ $label }}</p>
                        <p class="text-lg font-bold text-slate-800" dir="ltr">{{ $preview['metrics'][$key] ?? 'NOT AVAILABLE' }}</p>
                    </div>
                @endforeach
            </div>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">Condition</th><th class="px-3 py-2 text-left">Blocking</th><th class="px-3 py-2 text-left">Detail</th></tr></thead>
                    <tbody>
                    @forelse ($preview['conditions'] as $condition)
                        <tr class="border-t border-slate-100" data-testid="condition-{{ $condition['code'] }}"><td class="px-3 py-2 font-semibold">{{ $condition['code'] }}</td><td class="px-3 py-2">{{ $condition['blocking'] ? 'YES' : 'info' }}</td><td class="px-3 py-2 text-xs">{{ $condition['detail'] }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-3 py-3 text-center text-slate-500">لا شروط مانعة ولا ملاحظات.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ─── Close / Reopen (finance.close_period only) ───────────────────── --}}
    @if ($canClose)
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-close">
                <h2 class="text-base font-bold text-slate-800">إقفال الشهر (Close)</h2>
                <p class="mb-3 text-xs text-slate-500">اكتب <code dir="ltr">CLOSE {{ $month }}</code> حرفيًا. الخدمة تُعيد تقييم الشهر داخل المعاملة وترفض عند أي شرط مانع — حتى لو أظهرت المعاينة أعلاه <span dir="ltr">READY</span>. الحالة الحالية: <span dir="ltr">{{ $scope?->state ?? 'open' }}</span> (المؤشر المعروض <span dir="ltr">{{ $expectedCloseId === '' ? 'none' : '#'.$expectedCloseId }}</span>).</p>
                @include('livewire.dashboard.finance._payment_errors', ['form' => 'close'])
                <form wire:submit="close" class="grid gap-2">
                    <label class="text-sm">مفتاح المحاولة (idempotency)<input type="text" wire:model="closeKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="close-attempt-key"></label>
                    <label class="text-sm">الإقفال الحالي المتوقع<input type="text" wire:model="expectedCloseId" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="close-expected"></label>
                    <label class="text-sm">التأكيد<input type="text" wire:model="closeTyped" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="close-typed"></label>
                    <div>
                        @if ($confirming === 'close')
                            <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" dir="ltr" data-testid="close-confirm">Freeze {{ $month }} in {{ $reportingCurrency }} · expected pointer {{ $expectedCloseId === '' ? 'none' : '#'.$expectedCloseId }} · the snapshot and its hash become permanent</p>
                            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-50" data-testid="close-submit">تأكيد الإقفال</button>
                            <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">إلغاء</button>
                        @else
                            <button type="button" wire:click="openConfirm('close')" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800" data-testid="close-open">إقفال…</button>
                        @endif
                    </div>
                </form>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-reopen">
                <h2 class="text-base font-bold text-slate-800">إعادة الفتح (Reopen)</h2>
                <p class="mb-3 text-xs text-slate-500">سجل جديد يشير إلى الإقفال المعاد فتحه؛ الإقفال القديم لا يُمسّ. اكتب <code dir="ltr">REOPEN {{ $month }}</code> + سبب + دليل. مفتاح المحاولة هو مفتاح idempotency للخدمة: نفس المفتاح بنفس الحقائق يعيد السجل نفسه بلا كتابة ثانية، وبحقائق مختلفة = تعارض.</p>
                @include('livewire.dashboard.finance._payment_errors', ['form' => 'reopen'])
                <form wire:submit="reopen" class="grid gap-2 md:grid-cols-2">
                    <label class="text-sm">مفتاح المحاولة (idempotency)<input type="text" wire:model="reopenKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="reopen-attempt-key"></label>
                    <label class="text-sm">الإقفال الحالي المتوقع<input type="text" wire:model="expectedCloseId" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="reopen-expected"></label>
                    <label class="text-sm">معرّف الإقفال<input type="text" wire:model="reopenCloseId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="reopen-close-id"></label>
                    <label class="text-sm">التأكيد<input type="text" wire:model="reopenTyped" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="reopen-typed"></label>
                    <label class="text-sm">رمز السبب<input type="text" wire:model="reopenReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="reopen-reason"></label>
                    <label class="text-sm">مرجع الدليل<input type="text" wire:model="reopenEvidence" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="reopen-evidence"></label>
                    <div class="md:col-span-2">
                        @if ($confirming === 'reopen')
                            <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" dir="ltr" data-testid="reopen-confirm">Reopen close #{{ $reopenCloseId ?: '?' }} of {{ $month }} · expected pointer {{ $expectedCloseId === '' ? 'none' : '#'.$expectedCloseId }} · the frozen close stays exactly as recorded</p>
                            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50" data-testid="reopen-submit">تأكيد إعادة الفتح</button>
                            <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">إلغاء</button>
                        @else
                            <button type="button" wire:click="openConfirm('reopen')" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700" data-testid="reopen-open">إعادة الفتح…</button>
                        @endif
                    </div>
                </form>
            </section>
        </div>
    @else
        <p class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600" data-testid="read-only">عرض فقط: الإقفال وإعادة الفتح لـ<span dir="ltr">super_admin</span> فقط.</p>
    @endif

    {{-- ─── Frozen history ───────────────────────────────────────────────── --}}
    <section class="mt-6" data-testid="section-history">
        <h2 class="text-base font-bold text-slate-800">سجل الإقفال — {{ $month }}</h2>
        <p class="mb-2 text-xs text-slate-500">كل صف يُقرأ من الصفوف المجمَّدة فقط (<span dir="ltr">FROZEN CLOSE REVISION n</span>)؛ لا يُعاد تقييم أي إقفال عند العرض. للانحراف اضغط <span dir="ltr">CHECK CURRENT DRIFT</span> لصف واحد. القيم المجمَّدة لا تتغيّر في الحالتين.</p>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Basis</th><th class="px-3 py-2 text-left">Previous</th><th class="px-3 py-2 text-right">Reconciled Cash Contribution</th><th class="px-3 py-2 text-left">Hash</th><th class="px-3 py-2 text-left">Closed at (UTC)</th><th class="px-3 py-2 text-left">Flags</th><th class="px-3 py-2 text-left">Drift</th><th class="px-3 py-2 text-left">Links</th></tr></thead>
                <tbody>
                @forelse ($history as $record)
                    <tr class="border-t border-slate-100" data-testid="close-{{ $record->id }}">
                        <td class="px-3 py-2">{{ $record->id }}</td>
                        <td class="px-3 py-2 font-semibold">{{ strtoupper($record->status->value) }}</td>
                        <td class="px-3 py-2 text-xs">{{ $record->status->value === 'closed' ? 'FROZEN CLOSE REVISION '.$record->revision : 'reopen record (rev '.$record->revision.')' }}</td>
                        <td class="px-3 py-2">{{ $record->previous_close_id ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $record->status->value === 'closed' ? ($record->reconciled_cash_contribution ?? 'NOT AVAILABLE') : '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ $record->input_hash ? substr($record->input_hash, 0, 16).'…' : '—' }}</td>
                        <td class="px-3 py-2">{{ $record->closed_at->utc()->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2 text-xs text-amber-800">{{ $scope?->current_close_id === $record->id ? 'CURRENT' : '' }}{{ $record->status->value === 'reopened' ? ' reopened #'.$record->reopened_close_id.' ('.$record->reason_code.')' : '' }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if ($record->status->value === 'closed')
                                @if (array_key_exists($record->id, $drift))
                                    <span class="{{ $drift[$record->id] ? 'font-semibold text-amber-800' : 'text-emerald-700' }}" data-testid="drift-{{ $record->id }}">{{ $drift[$record->id] ? 'DRIFT SINCE CLOSE' : 'NO DRIFT' }}</span>
                                @else
                                    <button type="button" wire:click="checkDrift({{ $record->id }})" class="rounded border border-slate-300 px-2 py-0.5 text-[11px] text-slate-700 hover:bg-slate-50" data-testid="check-drift-{{ $record->id }}">CHECK CURRENT DRIFT</button>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">
                            <a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.close.show', $record->id) }}">detail</a>
                            @if ($canExport && $record->status->value === 'closed')
                                · <a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.close.export', $record->id) }}">CSV</a>
                            @endif
                            @if ($canClose && $record->status->value === 'closed' && $scope?->current_close_id === $record->id)
                                · <button type="button" wire:click="selectReopen({{ $record->id }})" class="text-amber-700 hover:underline" data-testid="select-reopen-{{ $record->id }}">reopen…</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-3 py-3 text-center text-slate-500">لا سجلات إقفال لهذا الشهر.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
