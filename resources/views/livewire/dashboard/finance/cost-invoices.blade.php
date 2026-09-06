<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">فواتير التكلفة (Cost Invoices)</h1>
            <p class="mt-1 text-sm text-slate-500">الفاتورة المؤكَّدة <strong>دليل</strong> لا تكلفة فعلية؛ التكلفة الفعلية تولد فقط من تسوية صريحة لنطاق واحد. الطرف مفتاح ثابت لا اسم. التوقيت <span dir="ltr">UTC</span>. الأسطر والتأكيد والإلغاء والاستبدال من صفحة تفاصيل الفاتورة.</p>
        </div>
        <a href="{{ route('dashboard.finance.reconciliation') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">نطاقات التسوية</a>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="invoices-disclaimer">
        Confirmed invoice = evidence only · signed lines (service / tax / other ≥ 0, credit ≤ 0) · Σ lines = total at confirmation · tax and other never enter service cost · Reconciled Cost / Variance: on the reconciliation pages · Gross Profit: <strong>NOT AVAILABLE</strong> · timezone <span dir="ltr">UTC</span>
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    {{-- ─── Filters (URL, allowlisted, bounded; page resets on change) ───── --}}
    <section class="mb-4" data-testid="section-filters">
        <div class="grid gap-3 md:grid-cols-4 lg:grid-cols-7">
            <label class="block text-sm"><span class="text-slate-600">من شهر الفترة (UTC)</span><input type="month" wire:model.live="fromMonth" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-from"></label>
            <label class="block text-sm"><span class="text-slate-600">إلى شهر (شامل)</span><input type="month" wire:model.live="toMonth" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-to"></label>
            <label class="block text-sm"><span class="text-slate-600">المكوّن</span>
                <select wire:model.live="component" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-component"><option value="">الكل</option>@foreach ($components as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
            <label class="block text-sm"><span class="text-slate-600">مفتاح الطرف</span><input type="text" wire:model.live.debounce.400ms="counterparty" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-counterparty"></label>
            <label class="block text-sm"><span class="text-slate-600">الحالة</span>
                <select wire:model.live="status" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-status"><option value="">الكل</option>@foreach ($statuses as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
            <label class="block text-sm"><span class="text-slate-600">العملة (تضييق ثانوي)</span>
                <select wire:model.live="currency" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-currency"><option value="">الكل</option>@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
            <label class="block text-sm"><span class="text-slate-600">مرجع الفاتورة (مطابقة تامة)</span><input type="text" wire:model.live.debounce.400ms="ref" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-ref"></label>
        </div>
        @if ($windowError)
            <p class="mt-2 text-sm text-rose-700" data-testid="window-error">{{ $windowError }}</p>
        @else
            <p class="mt-2 text-xs text-slate-500">نافذة شهر بداية فترة الفاتورة، حتى {{ $maxMonths }} شهرًا.</p>
        @endif
    </section>

    {{-- ─── Invoices (paginated, id desc) ────────────────────────────────── --}}
    <section class="mb-8" data-testid="section-invoices">
        <h2 class="text-base font-bold text-slate-800">الفواتير — {{ $invoices->total() }} rows · page {{ $invoices->currentPage() }} of {{ max(1, $invoices->lastPage()) }}</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                    <th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Component / counterparty</th><th class="px-3 py-2 text-left">Ref</th><th class="px-3 py-2 text-right">Signed total</th><th class="px-3 py-2 text-left">Invoice period (UTC)</th><th class="px-3 py-2 text-left">Issued (UTC)</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Superseded by</th><th class="px-3 py-2 text-left">Detail</th>
                </tr></thead>
                <tbody>
                @forelse ($invoices as $invoice)
                    <tr class="border-t border-slate-100" data-testid="invoice-{{ $invoice->id }}">
                        <td class="px-3 py-2">{{ $invoice->id }}</td>
                        <td class="px-3 py-2">{{ $invoice->component->value }} / {{ $invoice->counterparty_key }}</td>
                        <td class="px-3 py-2">{{ $invoice->invoice_ref ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $invoice->total_amount }} {{ $invoice->currency }}</td>
                        <td class="px-3 py-2">{{ $invoice->period_start->utc()->toDateString() }} → {{ $invoice->period_end->utc()->toDateString() }}</td>
                        <td class="px-3 py-2">{{ $invoice->issued_at->utc()->toDateString() }}</td>
                        <td class="px-3 py-2 font-semibold">{{ $invoice->current_status->value }}</td>
                        <td class="px-3 py-2">{{ $invoice->superseded_by_id ? '#'.$invoice->superseded_by_id : '—' }}</td>
                        <td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.cost_invoices.show', $invoice->id) }}">detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-3 text-center text-slate-500">لا فواتير مطابقة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-2">{{ $invoices->links() }}</div>
    </section>

    {{-- ─── Record invoice (draft) ───────────────────────────────────────── --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-invoice">
        <h2 class="text-base font-bold text-slate-800">تسجيل فاتورة (Record Invoice — draft)</h2>
        <p class="mb-3 text-xs text-slate-500">مسودة بمفتاح محاولة واحد = مفتاح idempotency الخدمة: ثابت حتى ينجح التسجيل؛ إعادة الإرسال بنفس المفتاح والحقائق لا تنشئ فاتورة ثانية، وبحقائق مختلفة = تعارض. الإجمالي = إجمالي المستند الموقَّع كاملًا (ضرائب/ائتمان/بنود أخرى). الطرف مفتاح ثابت (مزوّد ذكاء معروف للمكوّن provider). لا نص حر.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'invoice'])
        <form wire:submit="recordInvoice" class="grid gap-2 md:grid-cols-3">
            <label class="text-sm">مفتاح المحاولة (idempotency)<input type="text" wire:model="invKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="attempt-key"></label>
            <label class="text-sm">المكوّن<select wire:model="invComponent" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm">@foreach ($components as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
            <label class="text-sm">مفتاح الطرف<input type="text" wire:model="invCounterparty" dir="ltr" maxlength="64" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="inv-counterparty"></label>
            <label class="text-sm">مرجع الفاتورة (اختياري، فريد مع الطرف)<input type="text" wire:model="invRef" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">تاريخ الإصدار (UTC)<input type="date" wire:model="invIssuedAt" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">العملة (ISO 4217)<input type="text" wire:model="invCurrency" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="inv-currency"></label>
            <label class="text-sm">بداية فترة الفاتورة (UTC)<input type="date" wire:model="invPeriodStart" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">نهاية فترة الفاتورة (حصرية)<input type="date" wire:model="invPeriodEnd" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">الإجمالي الموقَّع<input type="text" wire:model="invTotal" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="inv-total"></label>
            <label class="text-sm">مرجع الدليل (اختياري، حتى 191)<input type="text" wire:model="invEvidence" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <div class="md:col-span-3"><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50" data-testid="invoice-submit">تسجيل المسودة</button></div>
        </form>
    </section>
</div>
