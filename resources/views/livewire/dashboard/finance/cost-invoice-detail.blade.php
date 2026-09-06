<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">الفاتورة #{{ $invoice->id }} — {{ $invoice->component->value }} / {{ $invoice->counterparty_key }} — {{ $invoice->total_amount }} {{ $invoice->currency }}</h1>
            <p class="mt-1 text-sm text-slate-500">حقائق الفاتورة كما سجّلتها خدمات E2: دليل فقط، لا تكلفة فعلية. التوقيت <span dir="ltr">UTC</span>. الطرف مفتاح ثابت. كل إجراء يعيد فحص الصلاحية server-side ويمرّ عبر الخدمة بالـtoken الذي عُرضت به الصفحة.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('dashboard.finance.cost_invoices') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50">قائمة الفواتير</a>
            @if ($canAudit)
                <a href="{{ $auditUrl }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50" data-testid="audit-link" title="cost_invoice.recorded / line_added / transitioned are recorded under this invoice subject">سجل التدقيق (read-only) — subject CostInvoice #{{ $invoice->id }}</a>
            @endif
        </div>
    </header>

    <x-finance.banners :warnings="$warnings" testid="invoice-banners" />

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    <section class="mb-6 grid gap-3 md:grid-cols-4" data-testid="facts" dir="ltr">
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Invoice id · ref</p><p class="text-lg font-bold" data-testid="fact-id">{{ $invoice->id }} · {{ $invoice->invoice_ref ?? '—' }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Component / counterparty key</p><p class="font-mono text-sm" data-testid="fact-scope">{{ $invoice->component->value }} / {{ $invoice->counterparty_key }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Signed total</p><p class="text-lg font-bold" data-testid="fact-total">{{ $invoice->total_amount }} {{ $invoice->currency }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Current status</p><p class="text-lg font-bold" data-testid="fact-status">{{ $invoice->current_status->value }}</p><p class="text-[11px] text-slate-400">lifecycle token <span class="font-mono" data-testid="fact-token">{{ $invoiceToken }}</span></p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Issued (UTC)</p><p class="font-mono text-sm" data-testid="fact-issued">{{ $invoice->issued_at->utc()->toDateString() }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Invoice period (UTC, end exclusive)</p><p class="font-mono text-sm" data-testid="fact-period">{{ $invoice->period_start->utc()->toDateString() }} → {{ $invoice->period_end->utc()->toDateString() }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Σ signed lines vs total</p><p class="font-mono text-sm {{ $sumMatches ? '' : 'text-amber-800' }}" data-testid="fact-sum">{{ $lineSum }} / {{ $total }} · {{ $sumMatches ? 'MATCH' : 'MISMATCH' }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Superseded by · evidence · recorded by</p><p class="font-mono text-xs" data-testid="fact-superseded">{{ $invoice->superseded_by_id ? '#'.$invoice->superseded_by_id : '—' }} · {{ $invoice->evidence_ref ?? '—' }} · {{ $invoice->recorded_by_ref }}</p></div>
    </section>

    {{-- ─── Lifecycle actions ────────────────────────────────────────────── --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="section-lifecycle">
        <h2 class="text-base font-bold text-slate-800">دورة الحياة (Confirm / Void / Supersede)</h2>
        <p class="mb-2 text-xs text-slate-500">الانتقالات الموجودة في E2 فقط: <code>draft → confirmed → voided | superseded</code>، <code>draft → voided</code>. التأكيد يجمّد الحقائق والأسطر ولا يحوّل الإجمالي إلى تكلفة فعلية. يُرسل token الحالة الذي عُرضت به الصفحة كحقل مخفي؛ إذا تغيّرت الحالة يُرفض الإجراء ولا يُعاد تلقائيًا.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'confirm'])
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'void'])
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'supersede'])
        <div class="flex flex-wrap gap-2">
            @if ($canConfirm)<button type="button" wire:click="openConfirm('confirm')" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700" data-testid="open-confirm">تأكيد (Confirm)</button>@endif
            @if ($canVoid)<button type="button" wire:click="openConfirm('void')" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700" data-testid="open-void">إلغاء (Void)</button>@endif
            @if ($canSupersede)<button type="button" wire:click="openConfirm('supersede')" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700" data-testid="open-supersede">استبدال (Supersede)</button>@endif
            @if (! $canConfirm && ! $canVoid && ! $canSupersede)<span class="text-sm text-slate-500" data-testid="no-transition">لا انتقال متاح من الحالة {{ $invoice->current_status->value }}.</span>@endif
        </div>
        @if (in_array($confirming, ['confirm', 'void', 'supersede'], true))
            <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3" data-testid="confirm-{{ $confirming }}" dir="ltr">
                <p class="text-sm font-semibold text-amber-900">Confirm {{ strtoupper($confirming) }} on invoice #{{ $invoice->id }} ({{ $invoice->total_amount }} {{ $invoice->currency }}, status {{ $invoice->current_status->value }}, token {{ $invoiceToken }})</p>
                <form wire:submit="{{ ['confirm' => 'confirmInvoice', 'void' => 'voidInvoice', 'supersede' => 'supersedeInvoice'][$confirming] }}" class="mt-2 grid gap-2 md:grid-cols-3">
                    <input type="hidden" wire:model="invoiceToken">
                    @if ($confirming === 'confirm')
                        <label class="text-sm">Evidence ref (optional, ≤ 191)<input type="text" wire:model="lcEvidence" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="lc-evidence"></label>
                        <p class="text-xs text-amber-900 md:col-span-2">Σ signed lines must equal the invoice total ({{ $lineSum }} / {{ $total }}); at least one line. The service re-checks under the invoice lock.</p>
                    @else
                        <label class="text-sm">Reason code (required, ≤ 32)<input type="text" wire:model="lcReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="lc-reason"></label>
                    @endif
                    @if ($confirming === 'supersede')
                        <label class="text-sm">Replacement (CONFIRMED, same component / counterparty / currency)
                            <select wire:model="lcReplacementId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="lc-replacement">
                                <option value="">—</option>
                                @foreach ($replacements as $candidate)<option value="{{ $candidate->id }}">#{{ $candidate->id }} · {{ $candidate->invoice_ref ?? 'no ref' }} · {{ $candidate->total_amount }} {{ $candidate->currency }} · {{ $candidate->period_start->utc()->toDateString() }} → {{ $candidate->period_end->utc()->toDateString() }}</option>@endforeach
                            </select></label>
                        @if ($replacements->isEmpty())<p class="text-xs text-amber-900" data-testid="no-replacement">No confirmed replacement candidate for {{ $invoice->component->value }} / {{ $invoice->counterparty_key }} / {{ $invoice->currency }}.</p>@endif
                    @endif
                    <div class="flex items-end gap-2">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-medium text-white hover:bg-amber-800 disabled:opacity-50" data-testid="confirm-submit">Confirm</button>
                        <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">Cancel</button>
                    </div>
                </form>
            </div>
        @endif
    </section>

    {{-- ─── Signed lines ─────────────────────────────────────────────────── --}}
    <section class="mb-6" data-testid="section-lines">
        <h2 class="text-base font-bold text-slate-800">الأسطر الموقَّعة (signed lines) — Σ {{ $lineSum }} / total {{ $total }}</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">line_no</th><th class="px-3 py-2 text-left">Kind</th><th class="px-3 py-2 text-left">Code</th><th class="px-3 py-2 text-right">Signed amount</th><th class="px-3 py-2 text-left">Allocatable</th><th class="px-3 py-2 text-right">Source allocated (all reconciliations)</th><th class="px-3 py-2 text-right">Remaining allocatable</th><th class="px-3 py-2 text-left">Line period (UTC)</th></tr></thead>
                <tbody>
                @forelse ($lineRows as $row)
                    <tr class="border-t border-slate-100" data-testid="line-{{ $row['line']->id }}">
                        <td class="px-3 py-2">{{ $row['line']->id }}</td><td class="px-3 py-2">{{ $row['line']->line_no }}</td><td class="px-3 py-2 font-semibold">{{ $row['line']->kind->value }}</td><td class="px-3 py-2">{{ $row['line']->description_code }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['line']->amount }} {{ $row['line']->currency }}</td>
                        <td class="px-3 py-2">{{ $row['allocatable'] ? 'ALLOCATABLE' : 'NOT ALLOCATABLE (never service cost)' }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['allocated'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['remaining'] ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row['line']->period_start ? $row['line']->period_start->utc()->toDateString().' → '.$row['line']->period_end->utc()->toDateString() : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-3 text-center text-slate-500">لا أسطر بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="section-add-line">
            @include('livewire.dashboard.finance._payment_errors', ['form' => 'line'])
            @if ($canAddLine)
                @if ($confirming !== 'line')
                    <button type="button" wire:click="openConfirm('line')" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700" data-testid="open-line">إضافة سطر (Add Line)</button>
                @else
                    <p class="mb-2 text-xs text-slate-500" dir="ltr">service / tax / other ≥ 0 · credit ≤ 0 · line_no unique within the invoice (a duplicate line_no is refused by the service, never replayed) · draft only.</p>
                    <form wire:submit="addLine" class="grid gap-2 md:grid-cols-3" data-testid="form-line">
                        <input type="hidden" wire:model="invoiceToken">
                        <label class="text-sm">مفتاح المحاولة<input type="text" wire:model="lineKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="line-key"></label>
                        <label class="text-sm">line_no<input type="text" wire:model="lineNo" dir="ltr" inputmode="numeric" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="line-no"></label>
                        <label class="text-sm">النوع<select wire:model="lineKind" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="line-kind">@foreach ($kinds as $k)<option value="{{ $k }}">{{ $k }}</option>@endforeach</select></label>
                        <label class="text-sm">رمز الوصف (حتى 64)<input type="text" wire:model="lineCode" dir="ltr" maxlength="64" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="line-code"></label>
                        <label class="text-sm">المبلغ الموقَّع ({{ $invoice->currency }})<input type="text" wire:model="lineAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="line-amount"></label>
                        <label class="text-sm">بداية فترة السطر (اختياري، UTC)<input type="date" wire:model="linePeriodStart" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                        <label class="text-sm">نهاية فترة السطر (اختياري، حصرية)<input type="date" wire:model="linePeriodEnd" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                        <div class="flex items-end gap-2 md:col-span-2">
                            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50" data-testid="line-submit">إضافة السطر</button>
                            <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">إلغاء</button>
                        </div>
                    </form>
                @endif
            @else
                <p class="text-sm text-slate-500" data-testid="lines-frozen">الأسطر مجمَّدة: تُضاف الأسطر لمسودة فقط (الحالة الآن {{ $invoice->current_status->value }}).</p>
            @endif
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section data-testid="section-events">
            <h2 class="text-base font-bold text-slate-800">سجل الأحداث (event trail)</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Event</th><th class="px-3 py-2 text-left">Occurred (UTC)</th><th class="px-3 py-2 text-left">Actor</th><th class="px-3 py-2 text-left">Reason</th><th class="px-3 py-2 text-left">Evidence</th></tr></thead>
                    <tbody>
                    @foreach ($events as $event)
                        <tr class="border-t border-slate-100" data-testid="event-{{ $event->id }}"><td class="px-3 py-2">{{ $event->id }}</td><td class="px-3 py-2 font-semibold">{{ $event->event_type->value }}</td><td class="px-3 py-2">{{ $event->occurred_at->utc()->format('Y-m-d H:i:s') }}</td><td class="px-3 py-2 font-mono text-xs">{{ $event->actor_ref }}</td><td class="px-3 py-2">{{ $event->reason_code ?? '—' }}</td><td class="px-3 py-2">{{ $event->evidence_ref ?? '—' }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <section data-testid="section-evidence-uses">
            <h2 class="text-base font-bold text-slate-800">التسويات التي استخدمت هذه الفاتورة كدليل</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">Allocation #</th><th class="px-3 py-2 text-left">Line</th><th class="px-3 py-2 text-right">Source share</th><th class="px-3 py-2 text-right">Converted</th><th class="px-3 py-2 text-left">FX</th><th class="px-3 py-2 text-left">Reconciliation</th><th class="px-3 py-2 text-left">Scope</th></tr></thead>
                    <tbody>
                    @forelse ($evidence as $row)
                        @php($rec = $reconciliations->get($row->cost_reconciliation_id))
                        <tr class="border-t border-slate-100" data-testid="evidence-{{ $row->id }}"><td class="px-3 py-2">{{ $row->id }}</td><td class="px-3 py-2">#{{ $row->cost_invoice_line_id }}</td><td class="px-3 py-2 text-right">{{ $row->source_amount ?? $row->amount }} {{ $row->source_currency ?? $row->currency }}</td><td class="px-3 py-2 text-right">{{ $row->amount }} {{ $row->currency }}</td><td class="px-3 py-2 text-xs">{{ $row->fxStatus() }}{{ $row->fx_rate_id ? ' · rate #'.$row->fx_rate_id.' · '.$row->fx_rate_snapshot.' · '.$row->fx_direction->value.' · '.$row->fx_rate_date->format('Y-m-d') : '' }}</td><td class="px-3 py-2">#{{ $row->cost_reconciliation_id }}{{ $rec ? ' · '.$rec->component->value.' / '.$rec->counterparty_key.' / '.$rec->period_start->utc()->format('Y-m') : '' }}</td><td class="px-3 py-2">@if ($rec)<a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.reconciliation.show', $rec->scope_id) }}">scope #{{ $rec->scope_id }}</a>@else —@endif</td></tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-3 text-center text-slate-500">لم تُستخدم كدليل بعد.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
