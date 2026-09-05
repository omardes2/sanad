{{-- Credentials + Test Connection panel for one provider row (Phase C3). $row from Providers::render(). --}}
@php $p = $row['provider']; $result = $healthResults[$p->id] ?? null; @endphp
<div class="grid gap-4 md:grid-cols-2">
    <section class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="text-sm font-semibold text-slate-700">المفاتيح</h3>
        <p class="mt-1 text-[11px] text-slate-500">
            وضع المفاتيح: <span class="font-medium">{{ $row['mode'] }}</span> —
            وقت التشغيل الآن: <span class="font-medium">{{ $row['runtime_source'] ?? '—' }}</span>
            @if ($row['runtime_fingerprint'])<code dir="ltr" class="rounded bg-slate-100 px-1">{{ $row['runtime_fingerprint'] }}</code>@endif
        </p>
        @if ($row['runtime_failure'])
            <div class="mt-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                المزوّد <strong>مغلق</strong>: مفتاح الخزنة الفعّال لا يمكن فتحه ({{ $row['runtime_failure'] }}). لا رجوع صامت إلى البيئة؛ للطوارئ: <code dir="ltr">AI_CREDENTIALS_MODE=env</code>.
            </div>
        @endif
        <div class="mt-2 text-xs text-slate-600">
            مفتاح البيئة:
            @if ($row['env_fingerprint'])
                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-800">موجود</span> <code dir="ltr" class="rounded bg-slate-100 px-1">{{ $row['env_fingerprint'] }}</code>
            @else
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">غير موجود</span>
            @endif
        </div>

        <table class="mt-3 w-full text-right text-xs">
            <thead class="text-slate-500"><tr>
                <th class="py-1 font-medium">البصمة</th><th class="py-1 font-medium">الحالة</th><th class="py-1 font-medium">آخر تحقق</th><th class="py-1 font-medium">مفتاح التشفير</th><th class="py-1"></th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($row['credentials'] as $c)
                    <tr wire:key="cred-{{ $c->id }}">
                        <td class="py-1.5 font-mono" dir="ltr">{{ $c->masked() }}@if ($c->label) <span class="font-sans text-slate-400">({{ $c->label }})</span>@endif</td>
                        <td class="py-1.5">
                            @if ($c->status->value === 'active')<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-800">{{ $c->status->label() }}</span>
                            @elseif ($c->status->value === 'pending')<span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">{{ $c->status->label() }}</span>
                            @else<span class="rounded-full bg-slate-200 px-2 py-0.5 text-slate-600">{{ $c->status->label() }}</span>@endif
                        </td>
                        <td class="py-1.5 font-mono text-[11px] text-slate-500" dir="ltr">{{ $c->last_verified_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="py-1.5 font-mono text-[11px] text-slate-500" dir="ltr">{{ $c->key_id }}@if ($vaultKeyId && $c->key_id !== $vaultKeyId) <span class="text-amber-700">(قديم)</span>@endif</td>
                        <td class="py-1.5 text-left">
                            @if ($c->status->value !== 'revoked')
                                @if ($canTest && $row['auth_probe'])
                                    <button type="button" wire:click="runCheck({{ $p->id }}, 'auth', {{ $c->id }}, false)" class="rounded border border-slate-300 px-2 py-0.5 text-[11px] hover:bg-slate-50">اختبار المصادقة</button>
                                @endif
                                @if ($canManageCredentials && $c->status->value === 'pending')
                                    @if ($row['verified'][$c->id] ?? false)
                                        <form method="POST" action="{{ route('dashboard.ai.credentials.activate', $c) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="expected_active_id" value="{{ $row['active_id'] }}">
                                            <button type="submit" onclick="return confirm('تفعيل هذا المفتاح وإلغاء الفعّال الحالي؟')" class="rounded bg-emerald-600 px-2 py-0.5 text-[11px] text-white hover:bg-emerald-700">تفعيل</button>
                                        </form>
                                    @elseif ($row['auth_probe'])
                                        <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500">يحتاج فحص مصادقة ناجحًا خلال {{ $verificationWindow }} دقيقة</span>
                                    @elseif (auth()->user()?->hasRole('super_admin'))
                                        <details class="inline">
                                            <summary class="cursor-pointer rounded border border-amber-400 px-2 py-0.5 text-[11px] text-amber-800">تفعيل بلا تحقق (قسري)</summary>
                                            <form method="POST" action="{{ route('dashboard.ai.credentials.activate_unverified', $c) }}" class="mt-1 flex gap-1">
                                                @csrf
                                                <input type="hidden" name="expected_active_id" value="{{ $row['active_id'] }}">
                                                <input type="text" name="confirmation" dir="ltr" autocomplete="off" placeholder="{{ $forceWord }}" class="w-32 rounded border-amber-300 text-[11px]">
                                                <button type="submit" class="rounded bg-amber-600 px-2 py-0.5 text-[11px] text-white">تأكيد</button>
                                            </form>
                                            <p class="text-[10px] text-amber-800">لا يوجد فحص مصادقة غير مفوتر لهذا الـadapter؛ يُسجَّل في الـAudit كتفعيل قسري بلا تحقق.</p>
                                        </details>
                                    @else
                                        <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500">لا فحص غير مفوتر؛ التفعيل القسري لـsuper_admin فقط</span>
                                    @endif
                                @endif
                                @if ($canManageCredentials)
                                    <details class="inline">
                                        <summary class="cursor-pointer rounded border border-rose-300 px-2 py-0.5 text-[11px] text-rose-700">إلغاء</summary>
                                        <form method="POST" action="{{ route('dashboard.ai.credentials.revoke', $c) }}" class="mt-1 flex gap-1">
                                            @csrf
                                            <input type="text" name="confirmation" dir="ltr" autocomplete="off" placeholder="{{ session('credential_routing_expected') ?? $p->key }}" class="w-40 rounded border-slate-300 text-[11px]">
                                            <button type="submit" class="rounded bg-rose-600 px-2 py-0.5 text-[11px] text-white">تأكيد الإلغاء</button>
                                        </form>
                                    </details>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-3 text-center text-slate-400">لا مفاتيح في الخزنة لهذا المزوّد.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($canManageCredentials)
            @if ($vaultAvailable)
                <form method="POST" action="{{ route('dashboard.ai.credentials.store', $p) }}" class="mt-3 grid gap-2 md:grid-cols-[1fr_auto_auto]" autocomplete="off">
                    @csrf
                    <input type="password" name="secret" dir="ltr" autocomplete="new-password" placeholder="مفتاح جديد (يُدخل مرة واحدة ولا يُعرض أبدًا)" class="rounded-lg border-slate-300 text-xs">
                    <input type="text" name="label" placeholder="وسم (اختياري)" class="rounded-lg border-slate-300 text-xs">
                    <button type="submit" class="rounded-lg border border-emerald-600 px-3 py-1 text-xs text-emerald-700 hover:bg-emerald-50">إضافة كمفتاح قيد الانتظار</button>
                </form>
                <p class="mt-1 text-[11px] text-slate-400">المسار: إضافة قيد الانتظار ← اختبار المصادقة الناجح لنفس المفتاح ← تفعيل (يُلغي الفعّال السابق في نفس المعاملة؛ يُرفض إن تغيّر الفعّال منذ فتح الصفحة).</p>
            @else
                <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-[11px] text-slate-500">الخزنة غير متاحة (CREDENTIALS_KEY غير مضبوط): لا يمكن إضافة مفاتيح. وقت التشغيل يعمل على مفاتيح البيئة.</p>
            @endif
        @endif
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="text-sm font-semibold text-slate-700">اختبار الاتصال</h3>
        @php $lh = $row['last_health']; @endphp
        <p class="mt-1 text-[11px] text-slate-500">
            آخر فحص:
            @if ($lh)
                {{ $lh->kind->label() }} — <span class="font-medium">{{ $lh->status->label() }}</span>
                <span class="font-mono" dir="ltr">{{ $lh->checked_at?->format('Y-m-d H:i') }}</span>@if ($lh->error_code) <code dir="ltr">{{ $lh->error_code }}</code>@endif
            @else — @endif
        </p>
        @if ($canTest)
            <div class="mt-2 flex flex-wrap gap-2">
                <button type="button" wire:click="runCheck({{ $p->id }}, 'connectivity', null, false)" class="rounded-lg border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50">الاتصال (بلا مفتاح)</button>
                @if ($row['auth_probe'])
                    <button type="button" wire:click="runCheck({{ $p->id }}, 'auth', null, false)" class="rounded-lg border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50">المصادقة (غير مفوتر) — المفتاح الفعلي</button>
                @else
                    <span class="rounded-lg bg-slate-100 px-3 py-1 text-xs text-slate-500">لا فحص مصادقة غير مفوتر لهذا الـadapter</span>
                @endif
                @if ($p->base_url)
                    <button type="button" wire:click="runCheck({{ $p->id }}, 'connectivity', null, true)" class="rounded-lg border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50">اتصال عبر base_url المخزَّن (مرشّح)</button>
                    @if ($row['auth_probe'])
                        <button type="button" wire:click="runCheck({{ $p->id }}, 'auth', null, true)" class="rounded-lg border border-slate-300 px-3 py-1 text-xs hover:bg-slate-50">مصادقة عبر base_url المخزَّن (مرشّح)</button>
                    @endif
                @endif
            </div>
            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
                <p class="text-[11px] text-amber-900"><strong>استدلال مفوتر</strong>: طلب واحد بحد أدنى من التوكنز يُسجَّل في دفتر الاستخدام كتكلفة على الشركة (بلا مشترك وبلا حصة). اكتب <code dir="ltr">{{ $inferenceWord }}</code> للتأكيد.</p>
                <div class="mt-1 flex gap-2">
                    <input type="text" wire:model="inferenceConfirmation" dir="ltr" autocomplete="off" placeholder="{{ $inferenceWord }}" class="w-28 rounded border-amber-300 text-xs">
                    <button type="button" wire:click="runCheck({{ $p->id }}, 'inference', null, false)" class="rounded-lg bg-amber-600 px-3 py-1 text-xs text-white hover:bg-amber-700">تشغيل الاستدلال المفوتر</button>
                </div>
            </div>
        @else
            <p class="mt-2 text-[11px] text-slate-400">تحتاج صلاحية ai.credentials.test لتشغيل الفحوصات.</p>
        @endif
        @if ($result)
            <div class="mt-3 rounded-lg bg-slate-50 p-3 text-xs" dir="ltr">
                @if (isset($result['error']))
                    <span class="text-rose-700">{{ $result['error'] }}</span>
                @else
                    <div><strong>{{ $result['kind'] }}</strong> → <span @class(['text-emerald-700' => $result['status'] === 'ok', 'text-amber-700' => $result['status'] === 'degraded', 'text-rose-700' => $result['status'] === 'failed', 'text-slate-500' => $result['status'] === 'skipped'])>{{ $result['status_label'] }}</span>
                        @if ($result['latency_ms'] !== null) · {{ $result['latency_ms'] }} ms @endif
                        @if ($result['http_status']) · HTTP {{ $result['http_status'] }} @endif
                        @if ($result['error_code']) · {{ $result['error_code'] }} @endif
                        · {{ $result['credential_source'] }}@if ($result['candidate']) · candidate base_url @endif
                        @if ($result['cost_incurred']) · <span class="text-amber-700">billed (usage #{{ $result['usage_event_id'] }})</span>@endif
                    </div>
                    @if ($result['details'])
                        <pre class="mt-1 whitespace-pre-wrap text-[11px] text-slate-600">{{ json_encode($result['details'], JSON_UNESCAPED_UNICODE) }}</pre>
                    @endif
                @endif
            </div>
        @endif
    </section>
</div>
