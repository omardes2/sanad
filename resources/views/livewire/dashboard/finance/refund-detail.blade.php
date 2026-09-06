@php
    use App\Livewire\Dashboard\Finance\Payments;
@endphp
<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">الاسترداد #{{ $refund->id }} — {{ $refund->amount }} {{ $refund->currency }}</h1>
            <p class="mt-1 text-sm text-slate-500">حقائق الاسترداد كما سجّلتها خدمة E1؛ النسب إلى تخصيصات الدفعة الأصلية فقط. التوقيت <span dir="ltr">UTC</span>. معرّفات فقط.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('dashboard.finance.refunds') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50">قائمة الاستردادات</a>
            <a href="{{ route('dashboard.finance.payments.show', $payment->id) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50" data-testid="payment-link">الدفعة #{{ $payment->id }}</a>
            @if ($canAudit)
                <a href="{{ $auditUrl }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50" data-testid="audit-link">سجل التدقيق (read-only)</a>
            @endif
        </div>
    </header>

    @php($warnings = $line->status === 'NOT CONVERTED' ? ['NOT CONVERTED · no current frozen conversion for this refund; reporting totals INCOMPLETE / NOT AVAILABLE'] : [])
    <x-finance.banners :warnings="$warnings" testid="refund-banners" />

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    <section class="mb-6 grid gap-3 md:grid-cols-4" data-testid="facts" dir="ltr">
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Refund id</p><p class="text-lg font-bold" data-testid="fact-id">{{ $refund->id }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Original payment</p><p class="text-lg font-bold" data-testid="fact-payment">#{{ $payment->id }} · {{ $payment->amount }} {{ $payment->currency }} · {{ $payment->current_status->value }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Amount</p><p class="text-lg font-bold" data-testid="fact-amount">{{ $refund->amount }} {{ $refund->currency }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Refunded at (UTC)</p><p class="font-mono text-sm" data-testid="fact-refunded">{{ $refund->refunded_at->utc()->format('Y-m-d H:i:s') }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Gateway / refund reference</p><p class="font-mono text-sm">{{ $refund->gateway }} · {{ $refund->gateway_refund_ref ?? '—' }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Reason · evidence</p><p class="font-mono text-xs">{{ $refund->reason_code }} · {{ $refund->evidence_ref ?? '—' }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Reporting</p><p class="text-lg font-bold {{ $line->status === 'NOT CONVERTED' ? 'text-amber-800' : '' }}" data-testid="fact-reporting">{{ $line->status }}{{ $line->reportingAmount() !== null ? ' · '.$line->reportingAmount() : '' }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Attributed / remaining attributable</p><p class="font-mono text-sm" data-testid="fact-attributable">{{ Payments::money($attributedCents) }} / {{ $remainingAttributable }}</p></div>
    </section>

    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="section-refund-allocation">
        <h2 class="text-base font-bold text-slate-800">نسب الاسترداد إلى تخصيص (Allocate Refund)</h2>
        <p class="mb-2 text-xs text-slate-500" dir="ltr">Targets: this payment's own allocations only, each with its remaining reversible amount (display only). Σ per refund ≤ refund and Σ per allocation ≤ allocation — enforced by the service, never clipped.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'refund_allocation'])
        @if ($confirming !== 'allocate')
            <button type="button" wire:click="openConfirm('allocate')" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700" data-testid="open-allocate">نسب الاسترداد</button>
        @else
            <form wire:submit="allocateRefund" class="grid gap-2 md:grid-cols-3" data-testid="form-refund-allocation">
                <input type="hidden" wire:model="paymentToken">
                <label class="text-sm">مفتاح المحاولة<input type="text" wire:model="allocationKey" dir="ltr" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" data-testid="allocation-key"></label>
                <label class="text-sm">التخصيص الهدف
                    <select wire:model="rallocAllocationId" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="ralloc-target">
                        <option value="">—</option>
                        @foreach ($targets as $target)
                            <option value="{{ $target->id }}">#{{ $target->id }} · {{ $target->period_start->utc()->toDateString() }} → {{ $target->period_end->utc()->toDateString() }} · {{ $target->amount }} {{ $target->currency }} · reversible {{ Payments::money(max(0, \App\Services\Payments\PaymentLedgerView::cents((string) $target->amount) - ($reversedCents[$target->id] ?? 0))) }}</option>
                        @endforeach
                    </select></label>
                <label class="text-sm">المبلغ ({{ $refund->currency }})<input type="text" wire:model="rallocAmount" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="ralloc-amount"></label>
                <label class="text-sm">رمز السبب (اختياري)<input type="text" wire:model="rallocReasonCode" dir="ltr" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <div class="flex gap-2 md:col-span-2">
                    <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700 disabled:opacity-50" data-testid="ralloc-submit">تأكيد النسب</button>
                    <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">إلغاء</button>
                </div>
            </form>
            @if ($targets->isEmpty())
                <p class="mt-2 text-xs text-amber-800" data-testid="no-targets">لا تخصيصات على الدفعة #{{ $payment->id }} يمكن نسب الاسترداد إليها.</p>
            @endif
        @endif
    </section>

    <section data-testid="section-allocations">
        <h2 class="text-base font-bold text-slate-800">سجل النسب (allocation history)</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Payment allocation</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2 text-left">Allocated (UTC)</th><th class="px-3 py-2 text-left">Actor</th><th class="px-3 py-2 text-left">Reason</th></tr></thead>
                <tbody>
                @forelse ($allocations as $row)
                    <tr class="border-t border-slate-100" data-testid="refund-allocation-{{ $row->id }}"><td class="px-3 py-2">{{ $row->id }}</td><td class="px-3 py-2">#{{ $row->payment_allocation_id }}</td><td class="px-3 py-2 text-right">{{ $row->amount }} {{ $row->currency }}</td><td class="px-3 py-2">{{ $row->allocated_at->utc()->format('Y-m-d H:i') }}</td><td class="px-3 py-2 font-mono text-xs">{{ $row->actor_ref }}</td><td class="px-3 py-2">{{ $row->reason_code ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-3 text-center text-slate-500">لم يُنسب بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
