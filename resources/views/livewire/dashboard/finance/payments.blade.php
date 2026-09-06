@php
    use App\Livewire\Dashboard\Finance\Payments;
@endphp
<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">المدفوعات</h1>
            <p class="mt-1 text-sm text-slate-500">كل الأرقام <strong>Cash Collected</strong> (نقد مستلم فعليًا بحسب أحداث الدفع) — ليست إيرادًا معترفًا به ولا ربحًا. التوقيت <span dir="ltr">UTC</span>. المشتركون بمعرّفهم الداخلي فقط. الاستردادات والتخصيصات وإجراءات النزاع من صفحة تفاصيل الدفعة.</p>
        </div>
        <a href="{{ route('dashboard.finance.refunds') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">الاستردادات</a>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="payments-disclaimer">
        Cash Collected · Refunds · Net Cash · Gateway Fees (NULL = <strong>FEES UNKNOWN</strong>, never 0) · Allocated Collected Amount (attribution only) · Revenue Recognition: <strong>NOT AVAILABLE</strong> · timezone <span dir="ltr">UTC</span>
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    {{-- ─── Filters (URL, allowlisted, bounded; page resets on change) ───── --}}
    <section class="mb-4" data-testid="section-filters">
        <div class="grid gap-3 md:grid-cols-4 lg:grid-cols-7">
            <label class="block text-sm"><span class="text-slate-600">من (UTC)</span><input type="date" wire:model.live="from" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">إلى (UTC، شامل)</span><input type="date" wire:model.live="to" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">العملة</span>
                <select wire:model.live="currency" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-currency"><option value="">الكل</option>@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
            <label class="block text-sm"><span class="text-slate-600">الحالة</span>
                <select wire:model.live="status" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-status"><option value="">الكل</option>@foreach ($statuses as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
            <label class="block text-sm"><span class="text-slate-600">معرّف المشترك</span><input type="text" wire:model.live.debounce.400ms="subscriber" dir="ltr" inputmode="numeric" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-subscriber"></label>
            <label class="block text-sm"><span class="text-slate-600">البوابة</span><input type="text" wire:model.live.debounce.400ms="gateway" dir="ltr" placeholder="manual" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-gateway"></label>
            <label class="block text-sm"><span class="text-slate-600">الرسوم</span>
                <select wire:model.live="fee" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-fee"><option value="">الكل</option><option value="known">KNOWN</option><option value="unknown">FEES UNKNOWN</option></select></label>
        </div>
        @if ($windowError)
            <p class="mt-2 text-sm text-rose-700" data-testid="window-error">{{ $windowError }}</p>
        @endif
    </section>

    {{-- ─── Cash summary (window) ────────────────────────────────────────── --}}
    <section class="mb-8" data-testid="section-cash">
        <h2 class="text-base font-bold text-slate-800">ملخّص النقد المحصَّل — نافذة UTC (حتى {{ $maxDays }} يومًا)</h2>
        @if ($windowError)
            <p class="text-sm text-slate-500">—</p>
        @elseif (! Payments::hasSummary($summary))
            <p class="text-sm text-slate-500">لا مدفوعات ولا استردادات في هذه النافذة.</p>
        @else
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                        <th class="px-3 py-2 text-left">Currency</th><th class="px-3 py-2 text-right">Payments</th><th class="px-3 py-2 text-right">Gross Cash Collected</th><th class="px-3 py-2 text-right">Refunds</th><th class="px-3 py-2 text-right">Net Cash</th><th class="px-3 py-2 text-right">Gateway Fees</th><th class="px-3 py-2 text-right">Net Cash After Gateway Fees</th><th class="px-3 py-2 text-right">Allocated Collected Amount</th><th class="px-3 py-2 text-right">Refund Allocated Amount</th><th class="px-3 py-2 text-right">Net Allocated Amount</th><th class="px-3 py-2 text-right">Unallocated Gross Collected Amount</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($summary as $currency => $row)
                        <tr class="border-t border-slate-100" data-testid="cash-{{ $currency }}">
                            <td class="px-3 py-2 font-semibold">{{ $currency }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->paymentsCount }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->grossCashCollected }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->refunds }} ({{ $row->refundsCount }})</td>
                            <td class="px-3 py-2 text-right">{{ $row->netCash }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->feesUnknownCount > 0 ? 'FEES UNKNOWN ('.$row->feesUnknownCount.' of '.$row->paymentsCount.')' : $row->gatewayFeesKnown }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->netCashAfterGatewayFees ?? 'NOT AVAILABLE' }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->allocatedCollectedAmount }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->refundAllocatedAmount }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->netAllocatedAmount }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->unallocatedGrossCollectedAmount }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ─── Payments (paginated, id desc) ────────────────────────────────── --}}
    <section class="mb-8" data-testid="section-payments">
        <h2 class="text-base font-bold text-slate-800">المدفوعات — {{ $payments->total() }} rows · page {{ $payments->currentPage() }} of {{ max(1, $payments->lastPage()) }}</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                    <th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Subscriber</th><th class="px-3 py-2 text-left">Gateway</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2 text-left">Received (UTC)</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Gateway fee</th><th class="px-3 py-2 text-right">Refunded</th><th class="px-3 py-2 text-right">Allocated</th><th class="px-3 py-2 text-left">Detail</th>
                </tr></thead>
                <tbody>
                @forelse ($payments as $payment)
                    <tr class="border-t border-slate-100" data-testid="payment-{{ $payment->id }}">
                        <td class="px-3 py-2">{{ $payment->id }}</td>
                        <td class="px-3 py-2">#{{ $payment->subscriber_id }}</td>
                        <td class="px-3 py-2">{{ $payment->gateway }}</td>
                        <td class="px-3 py-2 text-right">{{ $payment->amount }} {{ $payment->currency }}</td>
                        <td class="px-3 py-2">{{ $payment->received_at->utc()->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2">{{ $payment->current_status->value }}</td>
                        <td class="px-3 py-2">{{ $payment->feeIsKnown() ? $payment->gateway_fee_amount.' '.$payment->fee_currency : 'FEES UNKNOWN' }}</td>
                        <td class="px-3 py-2 text-right">{{ Payments::money($refundedCents[$payment->id] ?? 0) }}</td>
                        <td class="px-3 py-2 text-right">{{ Payments::money($allocatedCents[$payment->id] ?? 0) }}</td>
                        <td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.payments.show', $payment->id) }}">detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-3 py-3 text-center text-slate-500">لا مدفوعات مطابقة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-2">{{ $payments->links() }}</div>
    </section>

    {{-- ─── Record manual payment ────────────────────────────────────────── --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-payment">
        <h2 class="text-base font-bold text-slate-800">تسجيل دفعة يدوية (Record Manual Payment)</h2>
        <p class="mb-3 text-xs text-slate-500">تُسجَّل كـ <code>created → succeeded</code> في معاملة واحدة. مفتاح المحاولة ثابت حتى ينجح التسجيل؛ إعادة الإرسال بنفس المفتاح والحقائق لا تنشئ دفعة ثانية، وبحقائق مختلفة = تعارض. رسوم البوابة الفارغة = <strong>FEES UNKNOWN</strong> لا صفر.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'payment'])
        <form wire:submit="recordPayment" class="grid gap-2 md:grid-cols-3">
            <label class="text-sm">معرّف المشترك<input type="text" wire:model="subscriberId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">مفتاح المحاولة (idempotency)<input type="text" wire:model="idempotencyKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="attempt-key"></label>
            <label class="text-sm">المبلغ<input type="text" wire:model="amount" dir="ltr" placeholder="100.00" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">العملة (ISO 4217)<input type="text" wire:model="paymentCurrency" dir="ltr" placeholder="USD" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">تاريخ الاستلام (UTC)<input type="datetime-local" wire:model="receivedAt" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">مرجع البوابة (اختياري)<input type="text" wire:model="gatewayPaymentRef" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">رسوم البوابة (اختياري)<input type="text" wire:model="gatewayFeeAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">عملة الرسوم (= عملة الدفعة)<input type="text" wire:model="feeCurrency" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">مرجع (حتى 64)<input type="text" wire:model="reference" dir="ltr" maxlength="64" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm">رمز السبب (حتى 32)<input type="text" wire:model="reasonCode" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="text-sm md:col-span-2">مرجع الدليل (حتى 191)<input type="text" wire:model="evidenceRef" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <div class="md:col-span-3"><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">تسجيل الدفعة</button></div>
        </form>
    </section>
</div>
