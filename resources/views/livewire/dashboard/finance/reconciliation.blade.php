<div>
    <header class="mb-4">
        <h1 class="text-2xl font-bold text-slate-800">فواتير المزوّدين وتسوية التكلفة (Phase E2)</h1>
        <p class="mt-1 text-sm text-slate-500">الفاتورة المؤكَّدة <strong>دليل</strong> لا تكلفة فعلية. التكلفة الفعلية لا تولد إلا من <strong>تسوية</strong> صريحة لمكوّن واحد، لطرف واحد، لشهر تقويمي واحد (UTC)، بعملة واحدة. المحسوب في الدفتر لا يُمسّ؛ الفعلي يُسجَّل بجانبه. لا FX. لا أسماء ولا نص حر.</p>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="reconciliation-disclaimer">
        Confirmed invoice = evidence only · Reconciled Cost per (component, counterparty, month, currency) · Variance vs Known Calculated Cost only when calculated coverage is complete · CONFIRMED ZERO is an attestation, not $0 · Reconciled Cash Contribution / Period Close: <strong>NOT AVAILABLE — E4</strong> · Gross Profit: <strong>NOT AVAILABLE</strong>
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    {{-- ─── Reconciled cost summary ─────────────────────────────────────── --}}
    <section class="mb-8" data-testid="section-summary">
        <h2 class="text-lg font-bold text-slate-800">التكلفة المسوّاة — لكل نطاق</h2>
        <div class="mb-3 grid gap-3 md:grid-cols-4">
            <label class="block text-sm"><span class="text-slate-600">من شهر (UTC)</span><input type="month" wire:model.live="fromMonth" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">إلى شهر (UTC، شامل)</span><input type="month" wire:model.live="toMonth" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        </div>
        @if ($windowError)
            <p class="text-sm text-rose-700" data-testid="window-error">{{ $windowError }}</p>
        @elseif ($summary === [])
            <p class="text-sm text-slate-500">لا نطاقات تسوية في هذه الأشهر.</p>
        @else
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                        <th class="px-3 py-2 text-left">Scope</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-right">Base Reconciled Amount</th><th class="px-3 py-2 text-right">Adjustments</th><th class="px-3 py-2 text-right">Adjusted Reconciled Cost</th><th class="px-3 py-2 text-right">Known Calculated Cost (frozen)</th><th class="px-3 py-2 text-left">Calculated coverage</th><th class="px-3 py-2 text-right">Variance vs Known Calculated Cost</th><th class="px-3 py-2 text-right">Adjusted Variance vs Known Calculated Cost</th><th class="px-3 py-2 text-left">Flags</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($summary as $row)
                        <tr class="border-t border-slate-100" data-testid="scope-{{ $row->scopeId }}">
                            <td class="px-3 py-2">{{ $row->component }} / {{ $row->counterpartyKey }} / {{ $row->month }} / {{ $row->currency }} <span class="text-xs text-slate-400">#{{ $row->reconciliationId ?? '—' }}</span></td>
                            <td class="px-3 py-2 font-semibold">{{ $row->status }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->status === 'CONFIRMED ZERO' ? 'CONFIRMED ZERO' : ($row->baseReconciledAmount ?? '—') }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->adjustments }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->adjustedReconciledCost ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->calculatedKnownAmount ?? '—' }} <span class="text-xs text-slate-400">({{ $row->calculatedPricedRows ?? 0 }} priced, {{ $row->unpricedRows ?? 0 }} unpriced, {{ $row->currencyMismatchRows ?? 0 }} mismatch)</span></td>
                            <td class="px-3 py-2">{{ $row->coverage ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->varianceVsKnownCalculated ?? $row->varianceStatus }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->adjustedVarianceVsKnownCalculated ?? $row->varianceStatus }}</td>
                            <td class="px-3 py-2 text-xs text-amber-800">{{ implode(' · ', $row->flags) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- ─── Record invoice ────────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-invoice">
            <h2 class="text-base font-bold text-slate-800">تسجيل فاتورة (Record Invoice)</h2>
            <p class="mb-3 text-xs text-slate-500">مسودة بمفتاح idempotency مولَّد. الإجمالي = إجمالي المستند الموقَّع كاملًا (ضرائب/ائتمان/خدمات أخرى). الطرف مفتاح ثابت (مزوّد ذكاء معروف للمكوّن provider).</p>
            @error('invoice')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="invoice-error">{{ $message }}</p>@enderror
            <form wire:submit="recordInvoice" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">المكوّن<select wire:model="invComponent" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="provider">provider</option><option value="communication">communication</option><option value="external">external</option></select></label>
                <label class="text-sm">مفتاح الطرف<input type="text" wire:model="invCounterparty" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">مفتاح idempotency<input type="text" wire:model="invKey" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">مرجع الفاتورة (اختياري)<input type="text" wire:model="invRef" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">تاريخ الإصدار (UTC)<input type="date" wire:model="invIssuedAt" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">العملة<input type="text" wire:model="invCurrency" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">بداية فترة الفاتورة<input type="date" wire:model="invPeriodStart" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">نهاية فترة الفاتورة (حصرية)<input type="date" wire:model="invPeriodEnd" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">الإجمالي الموقَّع<input type="text" wire:model="invTotal" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">مرجع الدليل<input type="text" wire:model="invEvidence" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">تسجيل المسودة</button></div>
            </form>
        </section>

        {{-- ─── Add line ──────────────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-line">
            <h2 class="text-base font-bold text-slate-800">إضافة سطر (Add Line)</h2>
            <p class="mb-3 text-xs text-slate-500">service / tax / other ≥ 0 · credit ≤ 0 · Σ الأسطر الموقَّعة = الإجمالي عند التأكيد. الضرائب و"other" لا تدخل تكلفة الخدمة أبدًا.</p>
            @error('line')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="line-error">{{ $message }}</p>@enderror
            <form wire:submit="addLine" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">معرّف الفاتورة<input type="text" wire:model="lineInvoiceId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رقم السطر<input type="text" wire:model="lineNo" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">النوع<select wire:model="lineKind" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="service">service</option><option value="tax">tax</option><option value="credit">credit</option><option value="other">other</option></select></label>
                <label class="text-sm">رمز الوصف (حتى 32)<input type="text" wire:model="lineCode" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">المبلغ الموقَّع<input type="text" wire:model="lineAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div></div>
                <label class="text-sm">بداية فترة السطر (اختياري)<input type="date" wire:model="linePeriodStart" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">نهاية فترة السطر (اختياري)<input type="date" wire:model="linePeriodEnd" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">إضافة السطر</button></div>
            </form>
        </section>

        {{-- ─── Confirm / void / supersede ────────────────────────────────── --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-lifecycle">
            <h2 class="text-base font-bold text-slate-800">تأكيد / إلغاء / استبدال</h2>
            <p class="mb-3 text-xs text-slate-500">كل انتقال يتطلب token الحالة الذي رأيته (قديم ⇒ رفض). التأكيد يجمّد الحقائق والأسطر ولا يحوّل الإجمالي إلى تكلفة فعلية.</p>
            @error('lifecycle')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="lifecycle-error">{{ $message }}</p>@enderror
            <div class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">معرّف الفاتورة<input type="text" wire:model="lcInvoiceId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">token المتوقع<input type="text" wire:model="lcToken" dir="ltr" placeholder="i:123" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رمز السبب (للإلغاء/الاستبدال)<input type="text" wire:model="lcReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">الفاتورة البديلة (للاستبدال)<input type="text" wire:model="lcReplacementId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="flex gap-2 md:col-span-2">
                    <button type="button" wire:click="confirmInvoice" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">تأكيد</button>
                    <button type="button" wire:click="voidInvoice" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">إلغاء</button>
                    <button type="button" wire:click="supersedeInvoice" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">استبدال</button>
                </div>
            </div>
        </section>

        {{-- ─── Adjustment ────────────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-adjustment">
            <h2 class="text-base font-bold text-slate-800">تعديل بعد التسوية (Add Adjustment)</h2>
            <p class="mb-3 text-xs text-slate-500">سجل موقَّع append-only على التسوية الحالية فقط؛ المبلغ الأساسي لا يتغيّر. السبب والدليل إلزاميان.</p>
            @error('adjustment')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="adjustment-error">{{ $message }}</p>@enderror
            <form wire:submit="adjust" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">معرّف التسوية<input type="text" wire:model="adjReconciliationId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">المبلغ الموقَّع<input type="text" wire:model="adjAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رمز السبب<input type="text" wire:model="adjReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">مرجع الدليل<input type="text" wire:model="adjEvidence" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700">إضافة التعديل</button></div>
            </form>
        </section>
    </div>

    {{-- ─── Reconciliation ───────────────────────────────────────────────── --}}
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-reconciliation">
        <h2 class="text-base font-bold text-slate-800">إنشاء تسوية (Create Reconciliation) / صفر مؤكَّد (Confirm Zero)</h2>
        <p class="mb-3 text-xs text-slate-500">النطاق = مكوّن + طرف + شهر تقويمي UTC + عملة. المصدر <code>invoice</code>: تخصيصات دليل صريحة من أسطر فواتير مؤكَّدة (service موجب، credit سالب؛ لا proration تلقائي؛ المبلغ المسوّى = مجموعها). <code>manual_evidenced</code>: مبلغ + سبب + دليل. <code>confirmed_zero</code>: شهادة مالية بكتابة <strong>ZERO</strong> حرفيًا + سبب + دليل. أدخل معرّف التسوية الحالية المتوقعة (فارغ = لا تسوية بعد)؛ تغيّرها ⇒ رفض.</p>
        @error('reconciliation')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="reconciliation-error">{{ $message }}</p>@enderror
        <form wire:submit="reconcile" class="grid gap-2 md:grid-cols-3">
            <label class="text-sm">المكوّن<select wire:model="recComponent" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="provider">provider</option><option value="communication">communication</option><option value="external">external</option></select></label>
            <label class="text-sm">مفتاح الطرف<input type="text" wire:model="recCounterparty" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">الشهر (UTC)<input type="month" wire:model="recMonth" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">العملة<input type="text" wire:model="recCurrency" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">التسوية الحالية المتوقعة (فارغ = لا شيء)<input type="text" wire:model="recExpected" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">المصدر<select wire:model="recSource" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="invoice">invoice</option><option value="manual_evidenced">manual_evidenced</option><option value="confirmed_zero">confirmed_zero</option></select></label>
            @foreach ($recAllocations as $i => $row)
                <label class="text-sm">تخصيص دليل {{ $i + 1 }}: معرّف السطر<input type="text" wire:model="recAllocations.{{ $i }}.line" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm md:col-span-2">المبلغ الموقَّع<input type="text" wire:model="recAllocations.{{ $i }}.amount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            @endforeach
            <label class="text-sm">المبلغ (manual_evidenced فقط)<input type="text" wire:model="recAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">رمز السبب<input type="text" wire:model="recReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">مرجع الدليل<input type="text" wire:model="recEvidence" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">تأكيد الصفر (اكتب ZERO)<input type="text" wire:model="recTyped" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <div class="md:col-span-3"><button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">تسجيل التسوية</button></div>
        </form>
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section data-testid="section-invoices">
            <h2 class="text-base font-bold text-slate-800">آخر الفواتير</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Scope</th><th class="px-3 py-2 text-left">Ref</th><th class="px-3 py-2 text-right">Total</th><th class="px-3 py-2 text-left">Period</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Token</th></tr></thead>
                    <tbody>
                    @forelse ($invoices as $invoice)
                        <tr class="border-t border-slate-100" data-testid="invoice-{{ $invoice->id }}"><td class="px-3 py-2">{{ $invoice->id }}</td><td class="px-3 py-2">{{ $invoice->component->value }} / {{ $invoice->counterparty_key }}</td><td class="px-3 py-2">{{ $invoice->invoice_ref ?? '—' }}</td><td class="px-3 py-2 text-right">{{ $invoice->total_amount }} {{ $invoice->currency }}</td><td class="px-3 py-2">{{ $invoice->period_start->toDateString() }} → {{ $invoice->period_end->toDateString() }}</td><td class="px-3 py-2">{{ $invoice->current_status->value }}</td><td class="px-3 py-2 text-xs text-slate-500">{{ $invoice->stateToken() }}</td></tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-3 text-center text-slate-500">لا فواتير.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <h3 class="mt-4 text-sm font-bold text-slate-700">آخر الأسطر</h3>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Invoice</th><th class="px-3 py-2 text-left">No</th><th class="px-3 py-2 text-left">Kind</th><th class="px-3 py-2 text-left">Code</th><th class="px-3 py-2 text-right">Amount</th></tr></thead>
                    <tbody>
                    @forelse ($lines as $line)
                        <tr class="border-t border-slate-100"><td class="px-3 py-2">{{ $line->id }}</td><td class="px-3 py-2">#{{ $line->cost_invoice_id }}</td><td class="px-3 py-2">{{ $line->line_no }}</td><td class="px-3 py-2">{{ $line->kind->value }}</td><td class="px-3 py-2">{{ $line->description_code }}</td><td class="px-3 py-2 text-right">{{ $line->amount }} {{ $line->currency }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-3 text-center text-slate-500">لا أسطر.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section data-testid="section-scopes">
            <h2 class="text-base font-bold text-slate-800">نطاقات التسوية</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Scope</th><th class="px-3 py-2 text-left">Current reconciliation</th><th class="px-3 py-2 text-left">Version</th></tr></thead>
                    <tbody>
                    @forelse ($scopes as $scope)
                        <tr class="border-t border-slate-100"><td class="px-3 py-2">{{ $scope->id }}</td><td class="px-3 py-2">{{ $scope->component->value }} / {{ $scope->counterparty_key }} / {{ $scope->period_start->format('Y-m') }} / {{ $scope->currency }}</td><td class="px-3 py-2">{{ $scope->current_reconciliation_id ?? 'none' }}</td><td class="px-3 py-2">{{ $scope->version }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-3 text-center text-slate-500">لا نطاقات.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
