@php
    use App\Livewire\Dashboard\Finance\Payments;
@endphp
<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">الدفعة #{{ $payment->id }} — {{ $payment->amount }} {{ $payment->currency }}</h1>
            <p class="mt-1 text-sm text-slate-500">حقائق الدفعة كما سجّلتها خدمات E1: النقد المحصَّل تاريخيًا لا يتغير بأي إجراء هنا. التوقيت <span dir="ltr">UTC</span>. معرّفات فقط. كل إجراء يعيد فحص الصلاحية server-side ويمرّ عبر الخدمة نفسها.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('dashboard.finance.payments') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50">قائمة المدفوعات</a>
            @if ($canAudit)
                <a href="{{ $auditUrl }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50" data-testid="audit-link" title="payment.recorded / transitioned / refunded / allocated and refund.allocated are all recorded under this payment subject">سجل التدقيق (read-only) — subject CustomerPayment #{{ $payment->id }}</a>
            @endif
        </div>
    </header>

    <x-finance.banners :warnings="$warnings" testid="payment-banners" />

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    <section class="mb-6 grid gap-3 md:grid-cols-4" data-testid="facts" dir="ltr">
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Payment id</p><p class="text-lg font-bold" data-testid="fact-id">{{ $payment->id }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Subscriber id</p><p class="text-lg font-bold" data-testid="fact-subscriber">#{{ $payment->subscriber_id }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Gateway / reference</p><p class="font-mono text-sm" data-testid="fact-gateway">{{ $payment->gateway }} · {{ $payment->gateway_payment_ref ?? '—' }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Amount</p><p class="text-lg font-bold" data-testid="fact-amount">{{ $payment->amount }} {{ $payment->currency }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Received at (UTC)</p><p class="font-mono text-sm" data-testid="fact-received">{{ $payment->received_at->utc()->format('Y-m-d H:i:s') }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Current status</p><p class="text-lg font-bold" data-testid="fact-status">{{ $payment->current_status->value }}</p><p class="text-[11px] text-slate-400">state token <span class="font-mono" data-testid="fact-token">{{ $paymentToken }}</span></p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Gateway fee</p><p class="text-lg font-bold {{ $payment->feeIsKnown() ? '' : 'text-amber-800' }}" data-testid="fact-fee">{{ $payment->feeIsKnown() ? $payment->gateway_fee_amount.' '.$payment->fee_currency.' (known)' : 'FEES UNKNOWN' }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Reporting ({{ $reportingCurrency }})</p><p class="text-lg font-bold {{ $line->status === 'NOT CONVERTED' ? 'text-amber-800' : '' }}" data-testid="fact-reporting">{{ $line->status }}{{ $line->reportingAmount() !== null ? ' · '.$line->reportingAmount().' '.$reportingCurrency : '' }}</p>@if ($line->fxRateId)<p class="text-[11px] text-slate-400 font-mono">rate #{{ $line->fxRateId }} · {{ $line->fxRateDate }} · {{ $line->rateSnapshot }} · {{ $line->direction }}</p>@endif</div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Refunded / remaining refundable</p><p class="font-mono text-sm" data-testid="fact-refundable">{{ Payments::money($refundedCents) }} / {{ $remainingRefundable }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Allocated / remaining allocatable</p><p class="font-mono text-sm" data-testid="fact-allocatable">{{ Payments::money($allocatedCents) }} / {{ $remainingAllocatable }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3 md:col-span-2"><p class="text-[11px] text-slate-500">Reference · reason · evidence (bounded refs)</p><p class="font-mono text-xs">{{ $payment->reference ?? '—' }} · {{ $payment->reason_code ?? '—' }} · {{ $payment->evidence_ref ?? '—' }}</p></div>
    </section>

    {{-- ─── Lifecycle actions: only the legal transition is offered; the service is the authority ── --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="section-lifecycle">
        <h2 class="text-base font-bold text-slate-800">النزاع (dispute lifecycle)</h2>
        <p class="mb-2 text-xs text-slate-500">الانتقالات الموجودة في E1 فقط: <code>succeeded → disputed → dispute_resolved</code>. النزاع لا يمحو أو يعيد كتابة تاريخ التحصيل، وحلّه لا ينشئ دفعة جديدة. يُرسل token الحالة الذي عُرضت به الصفحة؛ إذا تغيّرت الحالة يُرفض الإجراء ولا يُعاد تلقائيًا.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'dispute'])
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'resolve'])
        <div class="flex flex-wrap gap-2">
            @if ($canDispute)
                <button type="button" wire:click="openConfirm('dispute')" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700" data-testid="open-dispute">تسجيل نزاع (Dispute)</button>
            @endif
            @if ($canResolve)
                <button type="button" wire:click="openConfirm('resolve')" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700" data-testid="open-resolve">حلّ النزاع (Resolve dispute)</button>
            @endif
            @if (! $canDispute && ! $canResolve)
                <span class="text-sm text-slate-500" data-testid="no-transition">لا انتقال متاح من الحالة {{ $payment->current_status->value }}.</span>
            @endif
        </div>
        @if ($confirming === 'dispute' || $confirming === 'resolve')
            <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3" data-testid="confirm-{{ $confirming }}" dir="ltr">
                <p class="text-sm font-semibold text-amber-900">Confirm {{ $confirming === 'dispute' ? 'DISPUTE' : 'RESOLVE DISPUTE' }} on payment #{{ $payment->id }} ({{ $payment->amount }} {{ $payment->currency }}, status {{ $payment->current_status->value }}, token {{ $paymentToken }})</p>
                <form wire:submit="{{ $confirming === 'dispute' ? 'dispute' : 'resolveDispute' }}" class="mt-2 grid gap-2 md:grid-cols-3">
                    <input type="hidden" wire:model="paymentToken">
                    <label class="text-sm">Reason code (required, ≤ 32)<input type="text" wire:model="transitionReason" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="transition-reason"></label>
                    <label class="text-sm">Evidence ref (optional, ≤ 191)<input type="text" wire:model="transitionEvidence" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    <div class="flex items-end gap-2">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-medium text-white hover:bg-amber-800 disabled:opacity-50" data-testid="confirm-submit">Confirm</button>
                        <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">Cancel</button>
                    </div>
                </form>
            </div>
        @endif
    </section>

    {{-- ─── Refund (from this payment) ────────────────────────────────────── --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="section-refund">
        <h2 class="text-base font-bold text-slate-800">استرداد (Refund)</h2>
        <p class="mb-2 text-xs text-slate-500" dir="ltr">payment {{ $payment->amount }} {{ $payment->currency }} · refunded {{ Payments::money($refundedCents) }} · remaining refundable {{ $remainingRefundable }} (display only — the service refuses any excess in full, never clips). refunded_at ≥ received_at and not in the future (UTC).</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'refund'])
        @if ($canRefund)
            @if ($confirming !== 'refund')
                <button type="button" wire:click="openConfirm('refund')" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700" data-testid="open-refund">تسجيل استرداد</button>
            @else
                <form wire:submit="recordRefund" class="grid gap-2 md:grid-cols-3" data-testid="form-refund">
                    <input type="hidden" wire:model="paymentToken">
                    <label class="text-sm">مفتاح المحاولة (idempotency)<input type="text" wire:model="refundKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="refund-key"></label>
                    <label class="text-sm">المبلغ ({{ $payment->currency }})<input type="text" wire:model="refundAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="refund-amount"></label>
                    <label class="text-sm">تاريخ الاسترداد (UTC)<input type="datetime-local" wire:model="refundedAt" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    <label class="text-sm">رمز السبب (إلزامي، حتى 32)<input type="text" wire:model="refundReasonCode" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    <label class="text-sm">مرجع الاسترداد لدى البوابة (اختياري)<input type="text" wire:model="refundGatewayRef" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    <label class="text-sm">مرجع الدليل (حتى 191)<input type="text" wire:model="refundEvidenceRef" dir="ltr" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    <div class="flex gap-2 md:col-span-3">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50" data-testid="refund-submit">تأكيد الاسترداد</button>
                        <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">إلغاء</button>
                    </div>
                </form>
            @endif
        @else
            <p class="text-sm text-slate-500" data-testid="refund-unavailable">الاسترداد متاح فقط لدفعة حالتها الحالية succeeded (الحالة الآن {{ $payment->current_status->value }}).</p>
        @endif
    </section>

    {{-- ─── Allocate (from this payment) ──────────────────────────────────── --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="section-allocation">
        <h2 class="text-base font-bold text-slate-800">نسب الدفعة إلى فترة خدمة (Allocate Payment)</h2>
        <p class="mb-2 text-xs text-slate-500" dir="ltr">allocated {{ Payments::money($allocatedCents) }} · remaining allocatable {{ $remainingAllocatable }} (display only; Σ ≤ payment enforced by the service, never clipped). Period = a subscription event of subscriber #{{ $payment->subscriber_id }} with a valid service period; never a hand-typed date.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'allocation'])
        @if ($canRefund)
            @if ($confirming !== 'allocate')
                <button type="button" wire:click="openConfirm('allocate')" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700" data-testid="open-allocate">تخصيص</button>
            @else
                <form wire:submit="allocatePayment" class="grid gap-2 md:grid-cols-3" data-testid="form-allocation">
                    <input type="hidden" wire:model="paymentToken">
                    <label class="text-sm">مفتاح المحاولة<input type="text" wire:model="allocationKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="allocation-key"></label>
                    <label class="text-sm">حدث الاشتراك (فترة صالحة فقط)
                        <select wire:model="allocEventId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="alloc-event">
                            <option value="">—</option>
                            @foreach ($eligibleEvents as $event)
                                <option value="{{ $event->id }}">#{{ $event->id }} · {{ $event->event_type->value }} · sub {{ $event->subscription_id }} · {{ $event->to_period_start->utc()->toDateString() }} → {{ $event->to_period_end->utc()->toDateString() }}</option>
                            @endforeach
                        </select></label>
                    <label class="text-sm">المبلغ ({{ $payment->currency }})<input type="text" wire:model="allocAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="alloc-amount"></label>
                    <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="allocReasonCode" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    <div class="flex gap-2 md:col-span-2">
                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-50" data-testid="allocation-submit">تأكيد التخصيص</button>
                        <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">إلغاء</button>
                    </div>
                </form>
                @if ($eligibleEvents->isEmpty())
                    <p class="mt-2 text-xs text-amber-800" data-testid="no-eligible-events">لا أحداث اشتراك بفترة صالحة للمشترك #{{ $payment->subscriber_id }}.</p>
                @endif
            @endif
        @else
            <p class="text-sm text-slate-500">التخصيص متاح فقط لدفعة حالتها الحالية succeeded.</p>
        @endif
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section data-testid="section-events">
            <h2 class="text-base font-bold text-slate-800">سجل الأحداث (event trail)</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Event</th><th class="px-3 py-2 text-left">Occurred (UTC)</th><th class="px-3 py-2 text-left">Source</th><th class="px-3 py-2 text-left">Actor</th><th class="px-3 py-2 text-left">Reason</th></tr></thead>
                    <tbody>
                    @foreach ($events as $event)
                        <tr class="border-t border-slate-100" data-testid="event-{{ $event->id }}"><td class="px-3 py-2">{{ $event->id }}</td><td class="px-3 py-2 font-semibold">{{ $event->event_type->value }}</td><td class="px-3 py-2">{{ $event->occurred_at->utc()->format('Y-m-d H:i:s') }}</td><td class="px-3 py-2">{{ $event->source->value }}</td><td class="px-3 py-2 font-mono text-xs">{{ $event->actor_ref }}</td><td class="px-3 py-2">{{ $event->reason_code ?? '—' }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <section data-testid="section-refunds">
            <h2 class="text-base font-bold text-slate-800">الاستردادات</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2 text-left">Refunded (UTC)</th><th class="px-3 py-2 text-left">Reason</th><th class="px-3 py-2 text-left">Detail</th></tr></thead>
                    <tbody>
                    @forelse ($refunds as $refund)
                        <tr class="border-t border-slate-100" data-testid="refund-{{ $refund->id }}"><td class="px-3 py-2">{{ $refund->id }}</td><td class="px-3 py-2 text-right">{{ $refund->amount }} {{ $refund->currency }}</td><td class="px-3 py-2">{{ $refund->refunded_at->utc()->format('Y-m-d H:i') }}</td><td class="px-3 py-2">{{ $refund->reason_code }}</td><td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.refunds.show', $refund->id) }}">detail</a></td></tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-3 text-center text-slate-500">لا استردادات.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="lg:col-span-2" data-testid="section-allocations">
            <h2 class="text-base font-bold text-slate-800">التخصيصات (attribution only)</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm" dir="ltr">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Event</th><th class="px-3 py-2 text-left">Subscription</th><th class="px-3 py-2 text-left">Period (UTC)</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2 text-right">Reversed by refunds</th><th class="px-3 py-2 text-left">Allocated (UTC)</th></tr></thead>
                    <tbody>
                    @forelse ($allocations as $allocation)
                        <tr class="border-t border-slate-100" data-testid="allocation-{{ $allocation->id }}"><td class="px-3 py-2">{{ $allocation->id }}</td><td class="px-3 py-2">#{{ $allocation->subscription_event_id }}</td><td class="px-3 py-2">#{{ $allocation->subscription_id }}</td><td class="px-3 py-2">{{ $allocation->period_start->utc()->toDateString() }} → {{ $allocation->period_end->utc()->toDateString() }}</td><td class="px-3 py-2 text-right">{{ $allocation->amount }} {{ $allocation->currency }}</td><td class="px-3 py-2 text-right">{{ Payments::money($reversedCents[$allocation->id] ?? 0) }}</td><td class="px-3 py-2">{{ $allocation->allocated_at->utc()->format('Y-m-d H:i') }}</td></tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-3 text-center text-slate-500">لا تخصيصات.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
