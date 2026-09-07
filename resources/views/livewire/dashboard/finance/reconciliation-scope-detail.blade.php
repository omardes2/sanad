@php
    use App\Support\Reconciliation\ReconciliationRules;
@endphp
<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">نطاق التسوية — {{ $identity['component'] }} / {{ $identity['counterparty'] }} / {{ $identity['month'] }} / {{ $identity['currency'] }}{{ $scope ? ' (scope #'.$scope->id.')' : ' (لا صف بعد)' }}</h1>
            <p class="mt-1 text-sm text-slate-500">مكوّن واحد، طرف واحد، شهر تقويمي UTC واحد، عملة واحدة. المراجعات append-only ولا تُعاد حسابات لقطاتها. عقد التزامن = المؤشر الحالي الذي عُرضت به الصفحة؛ تغيّره ⇒ رفض وتحديث بلا إعادة تنفيذ. الطرف مفتاح ثابت. التوقيت <span dir="ltr">UTC</span>.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('dashboard.finance.reconciliation') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50">قائمة النطاقات</a>
            @if ($canAudit)
                <a href="{{ $auditUrl }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:bg-slate-50" data-testid="audit-link" title="cost.reconciled and cost.adjusted are recorded under this scope subject">سجل التدقيق (read-only) — subject CostReconciliationScope #{{ $scope->id }}</a>
            @endif
        </div>
    </header>

    <x-finance.banners :blocking="$blocking" :info="$info" testid="scope-banners" />

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    <section class="mb-6 grid gap-3 md:grid-cols-4" data-testid="facts" dir="ltr">
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Scope identity</p><p class="font-mono text-sm" data-testid="fact-identity">{{ $identity['component'] }} / {{ $identity['counterparty'] }} / {{ $identity['month'] }} / {{ $identity['currency'] }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Current pointer · expected token</p><p class="font-mono text-sm" data-testid="fact-pointer">{{ $expectedId === '' ? 'none' : '#'.$expectedId }} · <span data-testid="fact-token">{{ $scopeToken }}</span></p><p class="text-[11px] text-slate-400">version {{ $scope?->version ?? 0 }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Status</p><p class="text-lg font-bold" data-testid="fact-status">{{ $current === null ? 'NOT RECONCILED' : ($current->source->value === 'confirmed_zero' ? 'CONFIRMED ZERO' : 'RECONCILED') }}</p><p class="text-[11px] text-slate-400">{{ $current ? 'source '.$current->source->value : '' }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3"><p class="text-[11px] text-slate-500">Base · Adjustments · Adjusted (current)</p><p class="font-mono text-sm" data-testid="fact-amounts">@if ($current){{ $current->source->value === 'confirmed_zero' ? 'CONFIRMED ZERO' : $current->reconciled_amount }} · {{ ReconciliationRules::format($adjustmentSums[$current->id] ?? 0) }} · {{ ReconciliationRules::format(\App\Services\Reconciliation\CostReconciliationService::scaledOf((string) $current->reconciled_amount) + ($adjustmentSums[$current->id] ?? 0)) }}@else — @endif</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3 md:col-span-2"><p class="text-[11px] text-slate-500">Live ledger status (this scope only, checked on render)</p><p class="text-sm {{ $live?->ledgerMoved ? 'font-semibold text-rose-800' : '' }}" data-testid="fact-live">{{ $live === null ? 'NOT RECONCILED — nothing to compare' : ($live->ledgerMoved ? 'LEDGER MOVED SINCE RECONCILIATION' : 'UNCHANGED SINCE RECONCILIATION') }}{{ $live && $live->flags !== [] ? ' · '.implode(' · ', $live->flags) : '' }}</p></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3 md:col-span-2"><p class="text-[11px] text-slate-500">Frozen calculated coverage (current)</p><p class="font-mono text-xs" data-testid="fact-coverage">@if ($current)known {{ $current->calculated_known_amount }} · {{ $current->calculated_priced_rows }} priced · {{ $current->unpriced_rows }} unpriced · {{ $current->currency_mismatch_rows }} mismatch · {{ $current->cost_coverage_status->label() }} · max event #{{ $current->ledger_max_event_id ?? '—' }}@else — @endif</p></div>
    </section>

    {{-- ─── Actions ──────────────────────────────────────────────────────── --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="section-actions">
        <h2 class="text-base font-bold text-slate-800">تسوية جديدة (new revision) / تعديل</h2>
        <p class="mb-2 text-xs text-slate-500" dir="ltr">expected current pointer = {{ $expectedId === '' ? 'none' : '#'.$expectedId }} (rendered with this page, sent back hidden). A changed pointer ⇒ <em>{{ \App\Livewire\Dashboard\Finance\ReconciliationScopeDetail::STALE_MESSAGE }}</em>, the page refreshes the pointer and any new reconciliation is a new manual decision — never an automatic retry.</p>
        @foreach (['reconcile', 'manual', 'zero', 'adjustment'] as $form)
            @include('livewire.dashboard.finance._payment_errors', ['form' => $form])
        @endforeach
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="openConfirm('evidence')" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700" data-testid="open-evidence">تسوية من دليل الفواتير</button>
            <button type="button" wire:click="openConfirm('manual')" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700" data-testid="open-manual">تسوية يدوية مُدلَّلة</button>
            <button type="button" wire:click="openConfirm('zero')" class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800" data-testid="open-zero">CONFIRMED ZERO</button>
            @if ($canAdjust)<button type="button" wire:click="openConfirm('adjust')" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700" data-testid="open-adjust">تعديل على التسوية الحالية</button>@endif
        </div>

        @if ($confirming === 'evidence')
            <form wire:submit="reconcileFromEvidence" class="mt-3 rounded-xl border border-emerald-300 bg-emerald-50 p-3" data-testid="form-evidence" dir="ltr">
                <input type="hidden" wire:model="expectedId"><input type="hidden" wire:model="scopeToken">
                <p class="text-sm font-semibold text-emerald-900">Reconcile {{ $identity['component'] }} / {{ $identity['counterparty'] }} / {{ $identity['month'] }} / {{ $identity['currency'] }} from CONFIRMED invoice evidence (expected pointer {{ $expectedId === '' ? 'none' : '#'.$expectedId }})</p>
                <p class="mb-2 text-xs text-emerald-900">Eligible evidence only: confirmed invoices of this component / counterparty, service / credit lines. You type each share explicitly (line currency, line sign) — no proration, no invoice total, no clipping; the service re-checks |Σ| ≤ |line| under the invoice lock. A cross-currency line requires an explicit fx_rate_id dated on the invoice's issued_at — no default, no latest, no nearest.</p>
                @if ($eligible->isEmpty())
                    <p class="text-sm text-amber-900" data-testid="no-eligible-evidence">No eligible evidence: no CONFIRMED invoice with service / credit lines for {{ $identity['component'] }} / {{ $identity['counterparty'] }}.</p>
                @endif
                <label class="text-xs">Attempt key<input type="text" wire:model="reconcileKey" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-xs" data-testid="reconcile-key"></label>
                @foreach ($evidenceRows as $i => $row)
                    @php($picked = $eligible->first(fn ($e) => (string) $e['line']->id === (string) ($row['line'] ?? '')))
                    @php($pickedInvoice = $picked['invoice'] ?? null)
                    <div class="mt-2 grid gap-2 md:grid-cols-4" data-testid="evidence-row-{{ $i }}">
                        <label class="text-sm">Evidence line
                            <select wire:model.live="evidenceRows.{{ $i }}.line" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="evidence-line-{{ $i }}">
                                <option value="">—</option>
                                @foreach ($eligible as $e)
                                    <option value="{{ $e['line']->id }}">line #{{ $e['line']->id }} · invoice #{{ $e['invoice']->id }}{{ $e['invoice']->invoice_ref ? ' ('.$e['invoice']->invoice_ref.')' : '' }} · {{ $e['line']->kind->value }} · {{ $e['line']->amount }} {{ $e['line']->currency }} · allocated {{ ReconciliationRules::format($e['allocated']) }} · remaining {{ ReconciliationRules::format($e['remaining']) }} · {{ $e['sign'] }} · issued {{ $e['invoice']->issued_at->utc()->toDateString() }}</option>
                                @endforeach
                            </select></label>
                        <label class="text-sm">Signed share (line currency{{ $pickedInvoice ? ' '.$pickedInvoice->currency : '' }})<input type="text" wire:model="evidenceRows.{{ $i }}.amount" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="evidence-amount-{{ $i }}"></label>
                        @if ($pickedInvoice && $pickedInvoice->currency !== $identity['currency'])
                            <label class="text-sm">fx_rate_id ({{ $pickedInvoice->currency }} → {{ $identity['currency'] }} dated {{ $pickedInvoice->issued_at->utc()->toDateString() }})
                                <select wire:model="evidenceRows.{{ $i }}.fx_rate_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="evidence-fx-{{ $i }}">
                                    <option value="">— choose explicitly —</option>
                                    @foreach ($quotes[$pickedInvoice->id] ?? [] as $rate)
                                        <option value="{{ $rate->id }}">rate #{{ $rate->id }} · {{ $rate->base_currency }}/{{ $rate->quote_currency }} {{ $rate->rate }} · {{ $rate->rateDate() }} (current revision)</option>
                                    @endforeach
                                </select></label>
                            @if (($quotes[$pickedInvoice->id] ?? []) === [])
                                <p class="text-xs font-semibold text-rose-800 md:col-span-1" data-testid="fx-required-{{ $i }}">FX_REQUIRED · no current quote {{ $pickedInvoice->currency }}/{{ $identity['currency'] }} dated {{ $pickedInvoice->issued_at->utc()->toDateString() }} — <a class="underline" href="{{ $fxUrl }}">record it on the FX page</a>, then reconcile. Nothing is converted implicitly.</p>
                            @endif
                        @else
                            <p class="text-xs text-slate-500 md:col-span-1">{{ $pickedInvoice ? 'NATIVE · same currency, no FX' : '' }}</p>
                        @endif
                        <div class="flex items-end"><button type="button" wire:click="removeEvidenceRow({{ $i }})" class="rounded border border-slate-300 px-2 py-1 text-xs">remove</button></div>
                    </div>
                @endforeach
                <div class="mt-2 grid gap-2 md:grid-cols-3">
                    <button type="button" wire:click="addEvidenceRow" class="rounded border border-slate-300 px-2 py-1 text-xs" data-testid="add-evidence-row">+ row</button>
                    <label class="text-sm">Reason code (optional, ≤ 32)<input type="text" wire:model="evReason" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    <label class="text-sm">Evidence ref (optional, ≤ 191)<input type="text" wire:model="evEvidence" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                </div>
                <div class="mt-2 flex gap-2">
                    <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-50" data-testid="reconcile-submit">Confirm reconciliation</button>
                    <button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">Cancel</button>
                </div>
            </form>
        @elseif ($confirming === 'manual')
            <form wire:submit="reconcileManual" class="mt-3 grid gap-2 rounded-xl border border-sky-300 bg-sky-50 p-3 md:grid-cols-4" data-testid="form-manual" dir="ltr">
                <input type="hidden" wire:model="expectedId"><input type="hidden" wire:model="scopeToken">
                <p class="text-sm font-semibold text-sky-900 md:col-span-4">Manual evidenced reconciliation (expected pointer {{ $expectedId === '' ? 'none' : '#'.$expectedId }}) — amount &gt; 0, reason and evidence required; no invoice allocations.</p>
                <label class="text-xs md:col-span-4">Attempt key<input type="text" wire:model="manualKey" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-xs"></label>
                <label class="text-sm">Reconciled amount ({{ $identity['currency'] }})<input type="text" wire:model="manAmount" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="manual-amount"></label>
                <label class="text-sm">Reason code (required)<input type="text" wire:model="manReason" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="manual-reason"></label>
                <label class="text-sm">Evidence ref (required)<input type="text" wire:model="manEvidence" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="manual-evidence"></label>
                <div class="flex items-end gap-2"><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" data-testid="manual-submit">Confirm</button><button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Cancel</button></div>
            </form>
        @elseif ($confirming === 'zero')
            <form wire:submit="confirmZero" class="mt-3 grid gap-2 rounded-xl border border-slate-400 bg-slate-100 p-3 md:grid-cols-4" data-testid="form-zero" dir="ltr">
                <input type="hidden" wire:model="expectedId"><input type="hidden" wire:model="scopeToken">
                <p class="text-sm font-semibold text-slate-900 md:col-span-4">CONFIRMED ZERO — a financial attestation that the actual cost of this scope is zero (expected pointer {{ $expectedId === '' ? 'none' : '#'.$expectedId }}). Type ZERO literally; reason and evidence required; no amount, no allocations.</p>
                <label class="text-xs md:col-span-4">Attempt key<input type="text" wire:model="zeroKey" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-xs"></label>
                <label class="text-sm">Type ZERO<input type="text" wire:model="zeroTyped" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="zero-typed"></label>
                <label class="text-sm">Reason code (required)<input type="text" wire:model="zeroReason" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="zero-reason"></label>
                <label class="text-sm">Evidence ref (required)<input type="text" wire:model="zeroEvidence" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="zero-evidence"></label>
                <div class="flex items-end gap-2"><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" data-testid="zero-submit">Attest CONFIRMED ZERO</button><button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Cancel</button></div>
            </form>
        @elseif ($confirming === 'adjust' && $canAdjust)
            <form wire:submit="adjust" class="mt-3 grid gap-2 rounded-xl border border-violet-300 bg-violet-50 p-3 md:grid-cols-4" data-testid="form-adjustment" dir="ltr">
                <input type="hidden" wire:model="scopeToken">
                <p class="text-sm font-semibold text-violet-900 md:col-span-4">Adjustment on the CURRENT reconciliation #{{ $current->id }} — signed amount ≠ 0, reason and evidence required. Base {{ $current->reconciled_amount }} never changes: Adjusted = Base + Σ adjustments. The attempt key is the durable service idempotency key (same key + same facts ⇒ the same adjustment; different facts ⇒ conflict).</p>
                <label class="text-xs md:col-span-4">Attempt key (idempotency)<input type="text" wire:model="adjustKey" readonly class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-xs" data-testid="adjust-key"></label>
                <label class="text-sm">Signed amount ({{ $current->currency }})<input type="text" wire:model="adjAmount" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="adjust-amount"></label>
                <label class="text-sm">Reason code (required)<input type="text" wire:model="adjReason" maxlength="32" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="adjust-reason"></label>
                <label class="text-sm">Evidence ref (required)<input type="text" wire:model="adjEvidence" maxlength="191" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="adjust-evidence"></label>
                <div class="flex items-end gap-2"><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-violet-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" data-testid="adjust-submit">Add adjustment</button><button type="button" wire:click="closeConfirm" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Cancel</button></div>
            </form>
        @endif
    </section>

    {{-- ─── Revision history (frozen) ────────────────────────────────────── --}}
    <section data-testid="section-revisions">
        <h2 class="text-base font-bold text-slate-800">سجل المراجعات (append-only، لقطات مجمَّدة لا تُعاد حسابها)</h2>
        @forelse ($revisions as $rev)
            @php($adjSum = $adjustmentSums[$rev->id] ?? 0)
            @php($base = \App\Services\Reconciliation\CostReconciliationService::scaledOf((string) $rev->reconciled_amount))
            @php($known = \App\Services\Reconciliation\CostReconciliationService::scaledOf((string) $rev->calculated_known_amount))
            <div class="mt-3 rounded-2xl border {{ $current && $rev->id === $current->id ? 'border-emerald-400' : 'border-slate-200' }} bg-white p-4" data-testid="revision-{{ $rev->id }}" dir="ltr">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-bold">Reconciliation #{{ $rev->id }} {{ $current && $rev->id === $current->id ? '· CURRENT' : '· superseded' }} · source {{ $rev->source->value }}{{ $rev->supersedes_id ? ' · supersedes #'.$rev->supersedes_id : '' }}</h3>
                    <span class="text-xs text-slate-500">{{ $rev->created_at->utc()->format('Y-m-d H:i:s') }} UTC · actor {{ $rev->actor_ref }} · reason {{ $rev->reason_code ?? '—' }} · evidence {{ $rev->evidence_ref ?? '—' }}</span>
                </div>
                <div class="mt-2 grid gap-2 text-sm md:grid-cols-4">
                    <div><p class="text-[11px] text-slate-500">Base Reconciled Amount</p><p class="font-mono" data-testid="rev-base-{{ $rev->id }}">{{ $rev->source->value === 'confirmed_zero' ? 'CONFIRMED ZERO' : $rev->reconciled_amount.' '.$rev->currency }}</p></div>
                    <div><p class="text-[11px] text-slate-500">Adjustments · Adjusted Reconciled Cost</p><p class="font-mono" data-testid="rev-adjusted-{{ $rev->id }}">{{ ReconciliationRules::format($adjSum) }} · {{ ReconciliationRules::format($base + $adjSum) }}</p></div>
                    <div><p class="text-[11px] text-slate-500">Frozen ledger snapshot</p><p class="font-mono text-xs" data-testid="rev-snapshot-{{ $rev->id }}">known {{ $rev->calculated_known_amount }} · priced {{ $rev->calculated_priced_rows }} · unpriced {{ $rev->unpriced_rows }} · mismatch {{ $rev->currency_mismatch_rows }} · max event #{{ $rev->ledger_max_event_id ?? '—' }} · {{ $rev->cost_coverage_status->label() }} · captured {{ $rev->captured_at->utc()->format('Y-m-d H:i:s') }} · hash {{ substr($rev->snapshot_hash, 0, 16) }}…</p></div>
                    <div><p class="text-[11px] text-slate-500">Variance vs Known Calculated Cost (frozen)</p><p class="font-mono" data-testid="rev-variance-{{ $rev->id }}">{{ $rev->cost_coverage_status->allowsVariance() ? ReconciliationRules::format($base - $known).' · adjusted '.ReconciliationRules::format($base + $adjSum - $known) : ($rev->cost_coverage_status->value === 'no_producer' ? 'UNKNOWN (NO PRODUCER)' : 'UNKNOWN (PARTIAL CALCULATED COVERAGE)') }}</p></div>
                </div>
                <div class="mt-2 grid gap-3 lg:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold text-slate-600">Evidence allocations (frozen FX facts)</p>
                        <table class="mt-1 min-w-full text-xs"><thead class="text-slate-500"><tr><th class="px-2 py-1 text-left">#</th><th class="px-2 py-1 text-left">Invoice / line</th><th class="px-2 py-1 text-right">Source share</th><th class="px-2 py-1 text-right">Converted</th><th class="px-2 py-1 text-left">FX</th></tr></thead><tbody>
                        @forelse ($evidenceByRevision->get($rev->id, collect()) as $row)
                            @php($line = $evidenceLines->get($row->cost_invoice_line_id))
                            <tr class="border-t border-slate-100" data-testid="allocation-{{ $row->id }}"><td class="px-2 py-1">{{ $row->id }}</td><td class="px-2 py-1"><a class="text-emerald-700 hover:underline" href="{{ route('dashboard.finance.cost_invoices.show', $row->cost_invoice_id) }}">invoice #{{ $row->cost_invoice_id }}</a> / line #{{ $row->cost_invoice_line_id }}{{ $line ? ' ('.$line->kind->value.' '.$line->line_no.')' : '' }}</td><td class="px-2 py-1 text-right">{{ $row->source_amount ?? $row->amount }} {{ $row->source_currency ?? $row->currency }}</td><td class="px-2 py-1 text-right">{{ $row->amount }} {{ $row->currency }}</td><td class="px-2 py-1">{{ $row->fxStatus() }}{{ $row->fx_rate_id ? ' · rate #'.$row->fx_rate_id.' · '.$row->fx_rate_snapshot.' · '.$row->fx_direction->value.' · '.$row->fx_rate_date->format('Y-m-d') : '' }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="px-2 py-1 text-slate-500">{{ $rev->source->value === 'invoice' ? '—' : 'no invoice evidence ('.$rev->source->value.')' }}</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-slate-600">Adjustments (append-only)</p>
                        <table class="mt-1 min-w-full text-xs"><thead class="text-slate-500"><tr><th class="px-2 py-1 text-left">#</th><th class="px-2 py-1 text-right">Signed amount</th><th class="px-2 py-1 text-left">Reason</th><th class="px-2 py-1 text-left">Evidence</th><th class="px-2 py-1 text-left">Actor · at (UTC)</th></tr></thead><tbody>
                        @forelse ($adjustments->get($rev->id, collect()) as $adj)
                            <tr class="border-t border-slate-100" data-testid="adjustment-{{ $adj->id }}"><td class="px-2 py-1">{{ $adj->id }}</td><td class="px-2 py-1 text-right">{{ $adj->amount }} {{ $adj->currency }}</td><td class="px-2 py-1">{{ $adj->reason_code }}</td><td class="px-2 py-1">{{ $adj->evidence_ref }}</td><td class="px-2 py-1">{{ $adj->actor_ref }} · {{ $adj->created_at->utc()->format('Y-m-d H:i:s') }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="px-2 py-1 text-slate-500">—</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                </div>
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500" data-testid="no-revisions">لا مراجعات بعد — النطاق NOT RECONCILED. أول تسوية تنشئ صف النطاق وتحرّك المؤشر.</p>
        @endforelse
    </section>
</div>
