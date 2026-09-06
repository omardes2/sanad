<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">تسوية التكلفة — النطاقات (Reconciliation scopes)</h1>
            <p class="mt-1 text-sm text-slate-500">التكلفة الفعلية لا تولد إلا من <strong>تسوية</strong> صريحة لمكوّن واحد، لطرف واحد، لشهر تقويمي واحد (UTC)، بعملة واحدة. القائمة تعرض الحقائق المخزَّنة فقط (المؤشر الحالي، المراجعة الحالية، اللقطة المجمَّدة)؛ حالة الدفتر الحية تُفحص عند الطلب لنطاق واحد. الكتابة من صفحة تفاصيل النطاق.</p>
        </div>
        <a href="{{ route('dashboard.finance.cost_invoices') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">فواتير التكلفة</a>
    </header>

    <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="reconciliation-disclaimer">
        Confirmed invoice = evidence only · Reconciled Cost per (component, counterparty, month, currency) · Variance vs Known Calculated Cost only when calculated coverage is complete (frozen at reconciliation) · CONFIRMED ZERO is an attestation, not $0 · live ledger status on demand only · Gross Profit: <strong>NOT AVAILABLE</strong> · timezone <span dir="ltr">UTC</span>
    </div>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    <section class="mb-4" data-testid="section-filters">
        <div class="grid gap-3 md:grid-cols-3 lg:grid-cols-6">
            <label class="block text-sm"><span class="text-slate-600">من شهر (UTC)</span><input type="month" wire:model.live="fromMonth" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-from"></label>
            <label class="block text-sm"><span class="text-slate-600">إلى شهر (شامل)</span><input type="month" wire:model.live="toMonth" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-to"></label>
            <label class="block text-sm"><span class="text-slate-600">المكوّن</span><select wire:model.live="component" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-component"><option value="">الكل</option>@foreach ($components as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
            <label class="block text-sm"><span class="text-slate-600">مفتاح الطرف</span><input type="text" wire:model.live.debounce.400ms="counterparty" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-counterparty"></label>
            <label class="block text-sm"><span class="text-slate-600">العملة</span><select wire:model.live="currency" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-currency"><option value="">الكل</option>@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
            <label class="block text-sm"><span class="text-slate-600">الحالة</span><select wire:model.live="status" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="filter-status"><option value="">الكل</option>@foreach ($statuses as $s)<option value="{{ $s }}">{{ strtoupper(str_replace('_', ' ', $s)) }}</option>@endforeach</select></label>
        </div>
        @if ($windowError)
            <p class="mt-2 text-sm text-rose-700" data-testid="window-error">{{ $windowError }}</p>
        @else
            <p class="mt-2 text-xs text-slate-500">نافذة شهر النطاق، حتى {{ $maxMonths }} شهرًا.</p>
        @endif
    </section>

    <section class="mb-8" data-testid="section-scopes">
        <h2 class="text-base font-bold text-slate-800">النطاقات — {{ $scopes->total() }} rows · page {{ $scopes->currentPage() }} of {{ max(1, $scopes->lastPage()) }}</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm" dir="ltr">
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                    <th class="px-3 py-2 text-left">Scope</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Current #</th><th class="px-3 py-2 text-right">Base Reconciled Amount</th><th class="px-3 py-2 text-right">Adjustments</th><th class="px-3 py-2 text-right">Adjusted Reconciled Cost</th><th class="px-3 py-2 text-right">Known Calculated Cost (frozen)</th><th class="px-3 py-2 text-left">Calculated coverage (frozen)</th><th class="px-3 py-2 text-right">Adjusted Variance vs Known Calculated Cost (frozen)</th><th class="px-3 py-2 text-left">Live ledger status</th><th class="px-3 py-2 text-left">Detail</th>
                </tr></thead>
                <tbody>
                @forelse ($rows as $row)
                    @php($scope = $row['scope'])
                    @php($check = $ledgerChecks[$scope->id] ?? null)
                    <tr class="border-t border-slate-100 align-top" data-testid="scope-{{ $scope->id }}">
                        <td class="px-3 py-2">{{ $scope->component->value }} / {{ $scope->counterparty_key }} / {{ $scope->period_start->utc()->format('Y-m') }} / {{ $scope->currency }} <span class="text-xs text-slate-400">scope #{{ $scope->id }}</span></td>
                        <td class="px-3 py-2 font-semibold">{{ $row['status'] }}</td>
                        <td class="px-3 py-2">{{ $row['rec'] ? '#'.$row['rec']->id.' · '.$row['rec']->source->value : '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['base'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['adjustments'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['adjusted'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['rec'] ? $row['rec']->calculated_known_amount.' ('.$row['rec']->calculated_priced_rows.' priced, '.$row['rec']->unpriced_rows.' unpriced, '.$row['rec']->currency_mismatch_rows.' mismatch)' : '—' }}</td>
                        <td class="px-3 py-2">{{ $row['coverage'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['variance'] ?? $row['varianceStatus'] }}</td>
                        <td class="px-3 py-2 text-xs" data-testid="ledger-status-{{ $scope->id }}">
                            @if ($check === null)
                                <span class="text-slate-500">LIVE LEDGER STATUS: NOT CHECKED</span>
                                <button type="button" wire:click="checkLedger({{ $scope->id }})" wire:loading.attr="disabled" class="ml-2 rounded border border-slate-300 px-2 py-0.5 text-xs hover:bg-slate-50" data-testid="check-ledger-{{ $scope->id }}">CHECK LEDGER</button>
                            @else
                                <span class="{{ str_starts_with($check['status'], 'LEDGER MOVED') ? 'font-semibold text-rose-800' : 'text-slate-700' }}">{{ $check['status'] }}</span>
                                <span class="text-slate-400">· checked {{ $check['at'] }} UTC</span>
                                @foreach ($check['flags'] as $flag)<div class="text-amber-800">{{ $flag }}</div>@endforeach
                                <button type="button" wire:click="checkLedger({{ $scope->id }})" class="ml-2 rounded border border-slate-300 px-2 py-0.5 text-xs hover:bg-slate-50">RE-CHECK</button>
                            @endif
                        </td>
                        <td class="px-3 py-2"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.reconciliation.show', $scope->id) }}">detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-3 py-3 text-center text-slate-500">لا نطاقات تسوية مطابقة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-2">{{ $scopes->links() }}</div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="form-scope">
        <h2 class="text-base font-bold text-slate-800">بدء تسوية لنطاق جديد (Start reconciliation)</h2>
        <p class="mb-3 text-xs text-slate-500">صف النطاق يُنشئه الخدمة عند أول تسوية. حدّد الهوية (مكوّن + مفتاح طرف + شهر UTC + عملة) لفتح صفحة التفاصيل؛ إن كان النطاق موجودًا تُفتح صفحته. لا كتابة هنا.</p>
        @include('livewire.dashboard.finance._payment_errors', ['form' => 'scope'])
        <form wire:submit="startScope" class="grid gap-2 md:grid-cols-5">
            <label class="text-sm">المكوّن<select wire:model="newComponent" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm">@foreach ($components as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
            <label class="text-sm">مفتاح الطرف<input type="text" wire:model="newCounterparty" dir="ltr" maxlength="64" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="new-counterparty"></label>
            <label class="text-sm">الشهر (UTC)<input type="month" wire:model="newMonth" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="new-month"></label>
            <label class="text-sm">العملة<input type="text" wire:model="newCurrency" dir="ltr" maxlength="3" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="new-currency"></label>
            <div class="flex items-end"><button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800" data-testid="scope-submit">فتح النطاق</button></div>
        </form>
    </section>
</div>
