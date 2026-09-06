@php
    use App\Livewire\Dashboard\Finance\Payments;
@endphp
<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">الاستردادات</h1>
            <p class="mt-1 text-sm text-slate-500">استردادات مسجَّلة بحسب <code>refunded_at</code> (UTC). معرّفات فقط. النسب إلى التخصيصات من صفحة تفاصيل الاسترداد.</p>
        </div>
        <a href="{{ route('dashboard.finance.payments') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">المدفوعات</a>
    </header>

    <section class="mb-4" data-testid="section-filters">
        <div class="grid gap-3 md:grid-cols-4">
            <label class="block text-sm"><span class="text-slate-600">من (UTC)</span><input type="date" wire:model.live="from" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">إلى (UTC، شامل)</span><input type="date" wire:model.live="to" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            <label class="block text-sm"><span class="text-slate-600">العملة</span>
                <select wire:model.live="currency" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-currency"><option value="">الكل</option>@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
            <label class="block text-sm"><span class="text-slate-600">معرّف الدفعة</span><input type="text" wire:model.live.debounce.400ms="payment" dir="ltr" inputmode="numeric" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-payment"></label>
        </div>
        @if ($windowError)
            <p class="mt-2 text-sm text-rose-700" data-testid="window-error">{{ $windowError }}</p>
        @endif
    </section>

    <section data-testid="section-refunds">
        <h2 class="text-base font-bold text-slate-800">الاستردادات — {{ $refunds->total() }} rows · page {{ $refunds->currentPage() }} of {{ max(1, $refunds->lastPage()) }} (حتى {{ $maxDays }} يومًا)</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Payment</th><th class="px-3 py-2 text-left">Gateway</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2 text-left">Refunded (UTC)</th><th class="px-3 py-2 text-left">Reason</th><th class="px-3 py-2 text-right">Attributed</th><th class="px-3 py-2 text-left">Detail</th></tr></thead>
                <tbody>
                @forelse ($refunds as $refund)
                    <tr class="border-t border-slate-100" data-testid="refund-{{ $refund->id }}"><td class="px-3 py-2">{{ $refund->id }}</td><td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.payments.show', $refund->customer_payment_id) }}">#{{ $refund->customer_payment_id }}</a></td><td class="px-3 py-2">{{ $refund->gateway }}</td><td class="px-3 py-2 text-right">{{ $refund->amount }} {{ $refund->currency }}</td><td class="px-3 py-2">{{ $refund->refunded_at->utc()->format('Y-m-d H:i') }}</td><td class="px-3 py-2">{{ $refund->reason_code }}</td><td class="px-3 py-2 text-right">{{ Payments::money($allocatedCents[$refund->id] ?? 0) }}</td><td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.refunds.show', $refund->id) }}">detail</a></td></tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-3 text-center text-slate-500">لا استردادات مطابقة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-2">{{ $refunds->links() }}</div>
    </section>
</div>
