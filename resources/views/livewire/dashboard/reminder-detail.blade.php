<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">تذكير #{{ $reminder->id }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $reminder->title }}</p>
        </div>
        <a href="{{ route('dashboard.reminders') }}" wire:navigate class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">عودة للقائمة</a>
    </header>

    <section class="mb-6 grid gap-3 md:grid-cols-3" data-testid="facts">
        @foreach ([
            'المشترك' => '#'.$reminder->user_id,
            'الموعد' => $reminder->remind_at?->format('Y-m-d H:i:s').' UTC',
            'التوقيت المحلي عند الجدولة' => $reminder->timezone,
            'القناة' => $reminder->channel?->label() ?? '—',
            'الحالة' => $reminder->status?->label() ?? '—',
            'المهمة المرتبطة' => $reminder->task_id !== null ? '#'.$reminder->task_id : '—',
            'رسالة المصدر' => $reminder->source_message_id !== null ? '#'.$reminder->source_message_id : '—',
            'أُرسِل في' => $reminder->sent_at?->format('Y-m-d H:i:s') ?? '—',
        ] as $label => $value)
            <div class="rounded-2xl border border-slate-200 bg-white p-3">
                <p class="text-[11px] text-slate-500">{{ $label }}</p>
                <p class="text-sm font-semibold text-slate-800" dir="ltr">{{ $value }}</p>
            </div>
        @endforeach
    </section>

    @if ($showDelivery)
        <section class="mb-6" data-testid="delivery">
            <h2 class="mb-2 text-lg font-bold text-slate-800">تفاصيل التسليم</h2>

            <div class="mb-3 grid gap-3 md:grid-cols-3">
                @foreach ([
                    'المحاولات الفعلية' => $reminder->attempts.' / 2',
                    'حُجِز في' => $reminder->claimed_at?->format('Y-m-d H:i:s') ?? '—',
                    'أُرسِل فعليًا في' => $reminder->dispatched_at?->format('Y-m-d H:i:s') ?? '—',
                    'حجز قديم' => $reminder->isStaleClaim() ? 'نعم — يستردّه الكانِس' : 'لا',
                    'رسالة التسليم' => $reminder->deliveredMessage?->id !== null ? '#'.$reminder->deliveredMessage->id : '—',
                    'أقصى تأخّر مقبول' => $maxLateness.' دقيقة',
                ] as $label => $value)
                    <div class="rounded-2xl border border-slate-200 bg-white p-3">
                        <p class="text-[11px] text-slate-500">{{ $label }}</p>
                        <p class="text-sm font-semibold text-slate-800" dir="ltr">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            @if ($rawError !== null && $rawError !== '')
                <div class="mb-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900" data-testid="failure-reason">
                    <p class="font-bold">{{ $reason?->label() ?? $rawError }}</p>
                    @if ($reason !== null)
                        <p class="mt-1 text-xs">{{ $reason->hint() }}</p>
                    @else
                        <p class="mt-1 text-xs">قيمة غير معروفة في <span dir="ltr">last_error</span>، تُعرض كما هي.</p>
                    @endif
                </div>
            @endif

            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600" data-testid="template-config">
                سياسة الرسالة الاستباقية: داخل نافذة {{ $freeFormWindowHours }} ساعة رسالة حرّة، وخارجها قالب معتمَد.
                القالب الآن: <strong>{{ $templateConfigured ? 'مضبوط' : 'غير مضبوط' }}</strong>.
                <span class="text-xs text-slate-500">هذه إعدادات لحظية، لا إعادة حساب لقرار التسليم — القرار يتّخذه المُرسِل وقت الإرسال وهو المرجع الوحيد.</span>
            </div>
        </section>
    @else
        <p class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600" data-testid="delivery-hidden">
            تفاصيل التسليم (سبب الفشل، الحجز، المحاولات) تحتاج صلاحية <span dir="ltr">reminders.delivery.view</span>.
        </p>
    @endif
</div>
