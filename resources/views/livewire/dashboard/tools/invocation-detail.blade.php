<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">استدعاء #{{ $invocation->id }}</h1>
            <p class="mt-1 text-sm text-slate-500" dir="ltr">{{ $invocation->toolKeyValue() }} · {{ $invocation->status?->label() }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('dashboard.tools.invocations') }}" wire:navigate class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">عودة للقائمة</a>
            @if ($canSeeConsents)
                <a href="{{ route('dashboard.tools.consents', ['subscriber_id' => $invocation->subscriber_id]) }}" wire:navigate class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="consents-link">موافقات هذا المشترك</a>
            @endif
        </div>
    </header>

    {{-- ─── The row ──────────────────────────────────────────────────────── --}}
    <section class="mb-6 grid gap-3 md:grid-cols-3" data-testid="facts">
        @foreach ([
            'الأداة' => $invocation->toolKeyValue(),
            'القدرة' => $invocation->capability,
            'الأثر الجانبي' => $invocation->side_effect?->label() ?? '—',
            'الحالة' => $invocation->status?->label() ?? '—',
            'سبب الرفض' => $invocation->refusal_reason?->label() ?? '—',
            'نوع الفشل' => $invocation->failure_kind?->label() ?? '—',
            'المشترك' => '#'.$invocation->subscriber_id,
            'الرسالة' => $invocation->message_id !== null ? '#'.$invocation->message_id : '—',
            'المحادثة' => $invocation->conversation_id !== null ? '#'.$invocation->conversation_id : '—',
            'خانة النداء' => (string) $invocation->call_index,
            'عدد الانتقالات' => (string) $invocation->version,
            'المدّة' => $invocation->duration_ms !== null ? $invocation->duration_ms.'ms' : '—',
            'بدأ' => $invocation->started_at?->format('Y-m-d H:i:s') ?? '—',
            'انتهى' => $invocation->finished_at?->format('Y-m-d H:i:s') ?? '—',
            'أُنشئ' => $invocation->created_at?->format('Y-m-d H:i:s') ?? '—',
        ] as $label => $value)
            <div class="rounded-2xl border border-slate-200 bg-white p-3">
                <p class="text-[11px] text-slate-500">{{ $label }}</p>
                <p class="text-sm font-semibold text-slate-800" dir="ltr">{{ $value }}</p>
            </div>
        @endforeach
    </section>

    {{-- ─── Identity ─────────────────────────────────────────────────────── --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4" data-testid="identity">
        <h2 class="mb-2 text-sm font-bold text-slate-700">الهوية والمدخل</h2>
        <p class="text-xs text-slate-500">مفتاح التكرار</p>
        <p class="mb-2 break-all font-mono text-xs text-slate-800" dir="ltr" data-testid="idempotency-key">{{ $invocation->idempotency_key }}</p>
        <p class="text-xs text-slate-500">بصمة المدخل القانوني</p>
        <p class="mb-3 break-all font-mono text-xs text-slate-800" dir="ltr" data-testid="input-hash">{{ $invocation->input_hash }}</p>

        <p class="text-xs text-slate-500">الحقول التي حملها النداء (أسماء فقط)</p>
        <p class="mb-2 font-mono text-xs text-slate-800" dir="ltr" data-testid="input-fields">{{ $invocation->input_fields === [] || $invocation->input_fields === null ? '—' : implode(', ', $invocation->input_fields) }}</p>

        <p class="text-xs text-slate-500">الجزء المحفوظ من المدخل</p>
        <pre class="overflow-x-auto rounded bg-slate-50 p-2 font-mono text-xs text-slate-800" dir="ltr" data-testid="input-json">{{ json_encode($invocation->input ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        <p class="mt-1 text-xs text-slate-500">وسائط الأدوات الخام لا تُحفظ أبدًا — هذا هو الجزء المسموح حفظه فقط، وهو فارغ لكل أداة شُحنت حتى الآن.</p>
    </section>

    {{-- ─── Output, labelled honestly ────────────────────────────────────── --}}
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4" data-testid="output">
        <h2 class="mb-2 text-sm font-bold text-slate-700">
            {{ $isRedacted ? 'بيانات وصفية عن المخرَج (ليست نتيجة الأداة)' : 'مخرَج الأداة' }}
        </h2>

        @if ($isRedacted)
            <div class="mb-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900" data-testid="redaction-notice">
                مخرَج هذه الأداة <strong>لا يُحفظ</strong>: ما في الصف إسقاط شكلي فقط (أعداد وأعلام).
                <strong>هذا ليس ما أعادته الأداة للنموذج</strong>، ولا يجوز قراءته كذلك — المحتوى لم يُخزَّن عن قصد،
                ومخرَج <span dir="ltr">memory.read@2</span> مثلًا يصل النموذج لحظتها ولا يُكتب هنا أبدًا.
                @if ($rehydratable)
                    وعند إعادة تشغيل مؤكَّدة لنفس الخانة، تُستنبَط النتيجة من البيانات الحيّة بموافقة سارية — لا من هذا الإسقاط.
                @endif
            </div>
        @endif

        <pre class="overflow-x-auto rounded bg-slate-50 p-2 font-mono text-xs text-slate-800" dir="ltr" data-testid="output-json">{{ $invocation->output === null ? '—' : json_encode($invocation->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </section>

    {{-- ─── Transitions ──────────────────────────────────────────────────── --}}
    <section data-testid="events">
        <h2 class="mb-2 text-lg font-bold text-slate-800">سجل الانتقالات</h2>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">#</th>
                        <th class="px-4 py-3 text-start font-medium">من</th>
                        <th class="px-4 py-3 text-start font-medium">إلى</th>
                        <th class="px-4 py-3 text-start font-medium">السبب</th>
                        <th class="px-4 py-3 text-start font-medium">الفاعل</th>
                        <th class="px-4 py-3 text-start font-medium">التاريخ</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($invocation->events as $event)
                    <tr class="border-t border-slate-100" data-testid="event-{{ $event->seq }}">
                        <td class="px-4 py-3" dir="ltr">{{ $event->seq }}</td>
                        <td class="px-4 py-3">{{ $event->from_status?->label() ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $event->to_status?->label() ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs" dir="ltr">{{ $event->reason_code ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs" dir="ltr">{{ $event->actor_ref ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500" dir="ltr">{{ $event->occurred_at?->format('Y-m-d H:i:s') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500" data-testid="no-events">لا توجد انتقالات مسجَّلة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
