<div>
    @php($close = $detail->close)
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">إقفال #{{ $close->id }} — {{ $close->month() }} ({{ $close->reporting_currency }})</h1>
            <p class="mt-1 text-sm text-slate-500">بيانات <strong>مجمَّدة</strong> كما سُجّلت وقت الإقفال: لا يُعاد حساب أي رقم من الحالة الحية. <strong>Reconciled Cash Contribution</strong> مقياس داخلي على أساس النقد — ليس ربحًا إجماليًا ولا هامشًا ولا إيرادًا.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('dashboard.finance.close', ['month' => $close->month()]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50">سجل الإقفال</a>
            @if ($canExport && $close->status->value === 'closed')
                <a href="{{ route('dashboard.finance.close.export', $close->id) }}" class="rounded-lg border border-emerald-600 px-3 py-1.5 font-medium text-emerald-700 hover:bg-emerald-50" data-testid="close-export-link">تصدير CSV (frozen close)</a>
            @endif
        </div>
    </header>

    <div class="mb-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" data-testid="basis" dir="ltr">
        Basis: <strong>{{ $detail->basisLabel() }}</strong> · status {{ strtoupper($close->status->value) }} · {{ $detail->isCurrent ? 'CURRENT close of its scope' : 'historical record (not the current pointer)' }} · timezone UTC · Gross Profit / Margin / Revenue Recognition: <strong>NOT AVAILABLE</strong>
    </div>

    <section class="mb-6 grid gap-3 md:grid-cols-4" data-testid="identity" dir="ltr">
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Revision</p><p class="text-lg font-bold">{{ $close->revision }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Previous close</p><p class="text-lg font-bold">@if ($close->previous_close_id)<a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.close.show', $close->previous_close_id) }}">#{{ $close->previous_close_id }}</a>@else —@endif</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Reopened close</p><p class="text-lg font-bold">@if ($close->reopened_close_id)<a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.close.show', $close->reopened_close_id) }}">#{{ $close->reopened_close_id }}</a>@else —@endif</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Reporting currency</p><p class="text-lg font-bold">{{ $close->reporting_currency }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3 md:col-span-2"><p class="text-[11px] text-slate-500">Period (UTC, half-open)</p><p class="font-mono text-sm">{{ $close->period_start->utc()->format('Y-m-d H:i:s') }} → {{ $close->period_end->utc()->format('Y-m-d H:i:s') }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Closed at (UTC)</p><p class="font-mono text-sm">{{ $close->closed_at->utc()->format('Y-m-d H:i:s') }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Actor</p><p class="font-mono text-sm">{{ $close->actor_ref }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3 md:col-span-4"><p class="text-[11px] text-slate-500">Canonical input hash (sha256 of the frozen inputs_snapshot)</p><p class="break-all font-mono text-xs" data-testid="input-hash">{{ $close->input_hash ?? '— (reopen record)' }}</p></div>
        @if ($close->status->value === 'reopened')
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-3 md:col-span-4 text-xs"><p>Reopen record: reason <span class="font-mono">{{ $close->reason_code }}</span> · evidence <span class="font-mono">{{ $close->evidence_ref }}</span> · typed <span class="font-mono">{{ $close->typed_confirmation }}</span></p></div>
        @endif
    </section>

    @if ($close->status->value === 'closed')
        <section class="mb-6" data-testid="section-figures">
            <h2 class="text-lg font-bold text-slate-800">الأرقام المجمَّدة — Frozen figures ({{ $close->reporting_currency }})</h2>
            <div class="mt-2 grid gap-3 md:grid-cols-4" dir="ltr">
                @foreach ($figures as $key)
                    @php($value = $close->getAttribute($key))
                    <div class="rounded-2xl border border-slate-200 bg-white p-3" data-testid="frozen-{{ $key }}">
                        <p class="text-[11px] text-slate-500">{{ ucwords(str_replace('_', ' ', $key)) }}</p>
                        <p class="text-lg font-bold text-slate-800">{{ $value === null ? 'NOT AVAILABLE' : (string) $value }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mb-6" data-testid="section-conditions">
            <h2 class="text-lg font-bold text-slate-800">الشروط المسجَّلة مع الإقفال — Frozen conditions</h2>
            @php($conditions = $detail->conditions())
            <x-finance.banners :frozen="true" testid="frozen-banners"
                :blocking="array_values(array_map(fn ($c) => $c['code'].' ('.($c['detail'] ?? '').')', array_filter($conditions, fn ($c) => $c['blocking'] ?? false)))"
                :info="array_values(array_map(fn ($c) => $c['code'].' ('.($c['detail'] ?? '').')', array_filter($conditions, fn ($c) => ! ($c['blocking'] ?? false))))" />
            @if ($conditions === [])
                <p class="text-sm text-slate-500">لا شروط مسجَّلة.</p>
            @endif
        </section>

        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4" data-testid="section-drift">
            <h2 class="text-base font-bold text-slate-800">CHECK CURRENT DRIFT</h2>
            <p class="text-xs text-slate-500">فحص عند الطلب فقط: يقارن hash التقييم الحي الآن بالـhash المجمَّد. النتيجة معلوماتية؛ القيم المجمَّدة أعلاه لا تتغير أبدًا.</p>
            <div class="mt-2 flex items-center gap-3">
                <button type="button" wire:click="checkDrift" class="rounded-lg border border-slate-400 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="check-drift">CHECK CURRENT DRIFT</button>
                @if ($drift !== null)
                    <span class="text-sm font-semibold {{ $drift ? 'text-amber-800' : 'text-emerald-700' }}" data-testid="drift-result" dir="ltr">{{ $drift ? 'DRIFT SINCE CLOSE — live data now differs from the frozen snapshot (frozen values unchanged)' : 'NO DRIFT — live data still matches the frozen snapshot' }}</span>
                @endif
            </div>
        </section>

        <section class="mb-6" data-testid="section-inputs">
            <h2 class="text-lg font-bold text-slate-800">صفوف المدخلات المجمَّدة — {{ $detail->inputCount() }} rows (from finance_period_close_inputs)</h2>
            @foreach ($detail->inputs as $type => $rows)
                <h3 class="mt-3 text-sm font-semibold text-slate-700" dir="ltr">{{ $type }} ({{ count($rows) }})</h3>
                <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                    <table class="min-w-full text-xs" dir="ltr" data-testid="inputs-{{ $type }}">
                        <thead class="bg-slate-50 text-slate-500"><tr>
                            <th class="px-2 py-1 text-left">id</th><th class="px-2 py-1 text-right">amount</th><th class="px-2 py-1 text-left">currency</th><th class="px-2 py-1 text-left">status</th>
                            <th class="px-2 py-1 text-right">reporting amount</th><th class="px-2 py-1 text-left">reporting currency</th>
                            <th class="px-2 py-1 text-left">conversion</th><th class="px-2 py-1 text-left">fx rate id</th><th class="px-2 py-1 text-left">rate date</th><th class="px-2 py-1 text-left">rate snapshot</th><th class="px-2 py-1 text-left">direction</th><th class="px-2 py-1 text-left">refs / flags</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($rows as $row)
                            <tr class="border-t border-slate-100" data-testid="input-{{ $type }}-{{ $row->input_id }}">
                                <td class="px-2 py-1 font-mono">{{ $row->input_id }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ $row->status === 'FEES UNKNOWN' ? '' : (string) $row->amount }}</td>
                                <td class="px-2 py-1">{{ $row->currency }}</td>
                                <td class="px-2 py-1 font-semibold">{{ $row->status }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ $row->reporting_amount === null ? 'NOT AVAILABLE' : (string) $row->reporting_amount }}</td>
                                <td class="px-2 py-1">{{ $row->reporting_currency }}</td>
                                <td class="px-2 py-1 font-mono">{{ $row->fx_conversion_id ?? '—' }}</td>
                                <td class="px-2 py-1 font-mono">{{ $row->fx_rate_id ?? '—' }}</td>
                                <td class="px-2 py-1 font-mono">{{ $row->fx_rate_id === null ? '—' : ($detail->rateDates[$row->fx_rate_id] ?? '—') }}</td>
                                <td class="px-2 py-1 font-mono">{{ $row->fx_rate_snapshot === null ? '—' : (string) $row->fx_rate_snapshot }}</td>
                                <td class="px-2 py-1">{{ $row->fx_direction ?? '—' }}</td>
                                <td class="px-2 py-1 text-slate-600">{{ implode(' · ', (array) $row->flags) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="px-2 py-2 text-center text-slate-400">none</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach
        </section>
    @endif

    <section class="mb-6" data-testid="section-audit">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold text-slate-800">سجل التدقيق — read-only</h2>
            @if ($canAudit)
                <a href="{{ $auditUrl }}" class="text-sm text-emerald-700 hover:underline" data-testid="audit-link">فتح في سجل التدقيق (scope #{{ $detail->scope->id }})</a>
            @endif
        </div>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-xs" dir="ltr">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-2 py-1 text-left">at (UTC)</th><th class="px-2 py-1 text-left">action</th><th class="px-2 py-1 text-left">actor</th><th class="px-2 py-1 text-left">pointer</th><th class="px-2 py-1 text-left">input hash</th></tr></thead>
                <tbody>
                @forelse ($auditEntries as $entry)
                    <tr class="border-t border-slate-100" data-testid="audit-{{ $entry->id }}">
                        <td class="px-2 py-1 font-mono">{{ $entry->created_at?->utc()->format('Y-m-d H:i:s') }}</td>
                        <td class="px-2 py-1 font-mono">{{ $entry->action }}</td>
                        <td class="px-2 py-1 font-mono">{{ $entry->actor_ref ?? $entry->actor }}</td>
                        <td class="px-2 py-1 font-mono">{{ ($entry->changes()['current_close_id']['from'] ?? '—') }} → {{ ($entry->changes()['current_close_id']['to'] ?? '—') }}</td>
                        <td class="px-2 py-1 font-mono">{{ substr((string) ($entry->context()['input_hash'] ?? $entry->context()['reopened_input_hash'] ?? ''), 0, 16) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-2 py-2 text-center text-slate-400">لا سجلات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
