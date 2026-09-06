@php
    use App\Livewire\Dashboard\Finance\Payments;
@endphp
<div>
    <header class="mb-4">
        <h1 class="text-2xl font-bold text-slate-800">المدفوعات (Phase E1)</h1>
        <p class="mt-1 text-sm text-slate-500">تسجيل يدوي للمدفوعات والاستردادات ونسب النقد المحصَّل إلى فترات خدمة الاشتراكات. كل الأرقام <strong>Cash Collected</strong> (نقد مستلم فعليًا بحسب أحداث الدفع) — ليست إيرادًا معترفًا به ولا ربحًا إجماليًا. التوقيت <span dir="ltr">UTC</span>. لا FX. المشتركون بمعرّفهم الداخلي فقط.</p>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="payments-disclaimer">
        Cash Collected · Refunds · Net Cash · Gateway Fees (NULL = <strong>FEES UNKNOWN</strong>) · Allocated Collected Amount (attribution only) · Revenue Recognition: <strong>NOT AVAILABLE</strong> · Gross Profit: <strong>NOT AVAILABLE</strong>
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    {{-- ─── Cash summary (window) ────────────────────────────────────────── --}}
    <section class="mb-8" data-testid="section-cash">
        <h2 class="text-lg font-bold text-slate-800">ملخّص النقد المحصَّل — نافذة UTC</h2>
        <p class="mb-3 text-xs text-slate-500">Gross Cash Collected بحسب <code>received_at</code> لدفعة لها حدث <code>succeeded</code>؛ Refunds بحسب <code>refunded_at</code>؛ التخصيصات بحسب بداية فترة الخدمة. حتى {{ $maxDays }} يومًا. العملات لا تُجمع.</p>
        <div class="mb-3 grid gap-3 md:grid-cols-4">
            <label class="block text-sm"><span class="text-slate-600">من (UTC)</span><input type="date" wire:model.live="from" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">إلى (UTC، شامل)</span><input type="date" wire:model.live="to" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
        </div>
        @if ($windowError)
            <p class="text-sm text-rose-700" data-testid="window-error">{{ $windowError }}</p>
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
                            <td class="px-3 py-2 text-right">{{ $row->feesUnknownCount > 0 ? 'FEES UNKNOWN ('.$row->feesUnknownCount.' unknown; known '.$row->gatewayFeesKnown.')' : $row->gatewayFeesKnown }}</td>
                            <td class="px-3 py-2 text-right">{{ $row->netCashAfterGatewayFees ?? 'FEES UNKNOWN' }}</td>
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

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- ─── Record manual payment ─────────────────────────────────────── --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-payment">
            <h2 class="text-base font-bold text-slate-800">تسجيل دفعة يدوية (Record Manual Payment)</h2>
            <p class="mb-3 text-xs text-slate-500">تُسجَّل كـ <code>created → succeeded</code> في معاملة واحدة. مفتاح idempotency يُولَّد مع النموذج؛ إعادة الإرسال بنفس المفتاح والحقائق لا تنشئ دفعة ثانية. رسوم البوابة الفارغة = <strong>UNKNOWN</strong> لا صفر.</p>
            @error('payment')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="payment-error">{{ $message }}</p>@enderror
            <form wire:submit="recordPayment" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">معرّف المشترك<input type="text" wire:model="subscriberId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">مفتاح idempotency<input type="text" wire:model="idempotencyKey" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">المبلغ<input type="text" wire:model="amount" dir="ltr" placeholder="100.00" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">العملة (ISO 4217)<input type="text" wire:model="currency" dir="ltr" placeholder="USD" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">تاريخ الاستلام (UTC)<input type="datetime-local" wire:model="receivedAt" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">مرجع البوابة (اختياري، لا يُخترع)<input type="text" wire:model="gatewayPaymentRef" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رسوم البوابة (اختياري)<input type="text" wire:model="gatewayFeeAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">عملة الرسوم (= عملة الدفعة)<input type="text" wire:model="feeCurrency" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">مرجع (حتى 64)<input type="text" wire:model="reference" dir="ltr" maxlength="64" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رمز السبب (حتى 32)<input type="text" wire:model="reasonCode" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm md:col-span-2">مرجع الدليل (حتى 191)<input type="text" wire:model="evidenceRef" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">تسجيل الدفعة</button></div>
            </form>
        </section>

        {{-- ─── Record refund ─────────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-refund">
            <h2 class="text-base font-bold text-slate-800">تسجيل استرداد (Record Refund)</h2>
            <p class="mb-3 text-xs text-slate-500">فقط لدفعة نجحت فعليًا؛ نفس العملة؛ مجموع الاستردادات ≤ مبلغ الدفعة (يُقبل كاملًا أو يُرفض كاملًا)؛ التاريخ ≥ استلام الدفعة.</p>
            @error('refund')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="refund-error">{{ $message }}</p>@enderror
            <form wire:submit="recordRefund" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">معرّف الدفعة<input type="text" wire:model="refundPaymentId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">مفتاح idempotency<input type="text" wire:model="refundKey" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">المبلغ<input type="text" wire:model="refundAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">تاريخ الاسترداد (UTC)<input type="datetime-local" wire:model="refundedAt" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رمز السبب (إلزامي، حتى 32)<input type="text" wire:model="refundReasonCode" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">مرجع الاسترداد لدى البوابة (اختياري)<input type="text" wire:model="refundGatewayRef" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm md:col-span-2">مرجع الدليل (حتى 191)<input type="text" wire:model="refundEvidenceRef" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">تسجيل الاسترداد</button></div>
            </form>
        </section>

        {{-- ─── Allocate payment ──────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-allocation">
            <h2 class="text-base font-bold text-slate-800">نسب دفعة إلى فترة خدمة (Allocate Payment)</h2>
            <p class="mb-3 text-xs text-slate-500">الفترة تُؤخذ من حدث اشتراك (E0) للمشترك نفسه ولا تُكتب يدويًا؛ يجوز توزيع دفعة على عدة أحداث؛ مجموع التخصيصات ≤ مبلغ الدفعة. إسناد فقط — ليس إيرادًا.</p>
            @error('allocation')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="allocation-error">{{ $message }}</p>@enderror
            <form wire:submit="allocatePayment" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">معرّف الدفعة<input type="text" wire:model.live.debounce.400ms="allocPaymentId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">حدث الاشتراك (بفترة صالحة)
                    <select wire:model="allocEventId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        <option value="">—</option>
                        @foreach ($events as $event)
                            <option value="{{ $event->id }}">#{{ $event->id }} · {{ $event->event_type->value }} · sub {{ $event->subscription_id }} · {{ $event->to_period_start->toDateString() }} → {{ $event->to_period_end->toDateString() }}</option>
                        @endforeach
                    </select></label>
                <label class="text-sm">المبلغ<input type="text" wire:model="allocAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="allocReasonCode" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">تخصيص</button></div>
            </form>
            @if ($allocPayment !== null && $events->isEmpty())
                <p class="mt-2 text-xs text-amber-800">لا أحداث اشتراك بفترة صالحة للمشترك #{{ $allocPayment->subscriber_id }}.</p>
            @endif
        </section>

        {{-- ─── Allocate refund ───────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-refund-allocation">
            <h2 class="text-base font-bold text-slate-800">نسب استرداد إلى تخصيص (Allocate Refund)</h2>
            <p class="mb-3 text-xs text-slate-500">سجل جديد فقط (append-only)؛ التخصيص الأصلي لا يُعدَّل. مجموع ما نُسب من الاسترداد ≤ الاسترداد، ومجموع ما نُسب على التخصيص ≤ التخصيص.</p>
            @error('refund_allocation')<p class="mb-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700" data-testid="refund-allocation-error">{{ $message }}</p>@enderror
            <form wire:submit="allocateRefund" class="grid gap-2 md:grid-cols-2">
                <label class="text-sm">معرّف الاسترداد<input type="text" wire:model="rallocRefundId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">معرّف التخصيص<input type="text" wire:model="rallocAllocationId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">المبلغ<input type="text" wire:model="rallocAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="rallocReasonCode" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="md:col-span-2"><button type="submit" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700">نسب الاسترداد</button></div>
            </form>
        </section>
    </div>

    {{-- ─── Recent payments ──────────────────────────────────────────────── --}}
    <section class="mt-8" data-testid="section-payments">
        <h2 class="text-lg font-bold text-slate-800">آخر المدفوعات</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                    <th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Subscriber</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2 text-left">Received (UTC)</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Gateway fee</th><th class="px-3 py-2 text-right">Refunded</th><th class="px-3 py-2 text-right">Allocated</th><th class="px-3 py-2 text-left">State</th>
                </tr></thead>
                <tbody>
                @forelse ($payments as $payment)
                    <tr class="border-t border-slate-100" data-testid="payment-{{ $payment->id }}">
                        <td class="px-3 py-2">{{ $payment->id }}</td>
                        <td class="px-3 py-2">#{{ $payment->subscriber_id }}</td>
                        <td class="px-3 py-2 text-right">{{ $payment->amount }} {{ $payment->currency }}</td>
                        <td class="px-3 py-2">{{ $payment->received_at->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2">{{ $payment->current_status->value }}</td>
                        <td class="px-3 py-2">{{ $payment->feeIsKnown() ? $payment->gateway_fee_amount.' '.$payment->fee_currency : 'FEES UNKNOWN' }}</td>
                        <td class="px-3 py-2 text-right">{{ Payments::money($refundedCents[$payment->id] ?? 0) }}</td>
                        <td class="px-3 py-2 text-right">{{ Payments::money($allocatedCents[$payment->id] ?? 0) }}</td>
                        <td class="px-3 py-2 text-xs text-slate-500">{{ $payment->stateToken() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-3 text-center text-slate-500">لا مدفوعات بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section data-testid="section-refunds">
            <h2 class="text-base font-bold text-slate-800">آخر الاستردادات</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Payment</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2 text-left">Refunded (UTC)</th><th class="px-3 py-2 text-left">Reason</th></tr></thead>
                    <tbody>
                    @forelse ($refunds as $refund)
                        <tr class="border-t border-slate-100"><td class="px-3 py-2">{{ $refund->id }}</td><td class="px-3 py-2">#{{ $refund->customer_payment_id }}</td><td class="px-3 py-2 text-right">{{ $refund->amount }} {{ $refund->currency }}</td><td class="px-3 py-2">{{ $refund->refunded_at->format('Y-m-d H:i') }}</td><td class="px-3 py-2">{{ $refund->reason_code }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-3 text-center text-slate-500">لا استردادات.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section data-testid="section-allocations">
            <h2 class="text-base font-bold text-slate-800">آخر التخصيصات</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Payment</th><th class="px-3 py-2 text-left">Event</th><th class="px-3 py-2 text-left">Subscription</th><th class="px-3 py-2 text-left">Period</th><th class="px-3 py-2 text-right">Amount</th></tr></thead>
                    <tbody>
                    @forelse ($allocations as $allocation)
                        <tr class="border-t border-slate-100"><td class="px-3 py-2">{{ $allocation->id }}</td><td class="px-3 py-2">#{{ $allocation->customer_payment_id }}</td><td class="px-3 py-2">#{{ $allocation->subscription_event_id }}</td><td class="px-3 py-2">#{{ $allocation->subscription_id }}</td><td class="px-3 py-2">{{ $allocation->period_start->toDateString() }} → {{ $allocation->period_end->toDateString() }}</td><td class="px-3 py-2 text-right">{{ $allocation->amount }} {{ $allocation->currency }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-3 text-center text-slate-500">لا تخصيصات.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
