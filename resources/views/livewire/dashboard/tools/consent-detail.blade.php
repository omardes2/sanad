<div>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">موافقة #{{ $consent->id }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $consent->capability->label() }} — المشترك <span dir="ltr">#{{ $consent->subscriber_id }}</span></p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('dashboard.tools.consents') }}" wire:navigate class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">عودة للقائمة</a>
            @if ($canAudit)
                <a href="{{ route('dashboard.audit', ['subject_type' => 'ToolConsent', 'subject_id' => $consent->id]) }}" wire:navigate class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" data-testid="audit-link">سجل التدقيق</a>
            @endif
        </div>
    </header>

    @if ($notice)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-testid="notice">{{ $notice }}</div>
    @endif

    @error('revoke')
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800" data-testid="revoke-error">{{ $message }}</div>
    @enderror

    <section class="mb-6 grid gap-3 md:grid-cols-3" data-testid="facts">
        @foreach ([
            'الحالة' => $consent->status->label(),
            'النسخة' => (string) $consent->version,
            'القدرة' => $consent->capability->value,
            'مُنحت في' => $consent->granted_at?->format('Y-m-d H:i:s') ?? '—',
            'سُحبت في' => $consent->revoked_at?->format('Y-m-d H:i:s') ?? '—',
            'السبب المسجَّل' => $consent->reason_code->label(),
            'مرجع الإثبات' => $consent->evidence_ref ?? '—',
            'آخر فاعل' => $consent->updated_by_ref,
            'آخر تحديث' => $consent->updated_at?->format('Y-m-d H:i:s') ?? '—',
        ] as $label => $value)
            <div class="rounded-2xl border border-slate-200 bg-white p-3">
                <p class="text-[11px] text-slate-500">{{ $label }}</p>
                <p class="text-sm font-semibold text-slate-800" dir="ltr">{{ $value }}</p>
            </div>
        @endforeach
    </section>

    {{-- ─── Revoke only. There is no grant action anywhere. ──────────────── --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-4" data-testid="revoke-section">
        <h2 class="mb-1 text-sm font-bold text-slate-700">سحب الموافقة</h2>
        <p class="mb-3 text-xs text-slate-500">
            الموظّف يستطيع <strong>تقليل</strong> صلاحية سَنَد لا إنشاءها: لا يوجد زر منح في هذه اللوحة، ولا صلاحية تمنحه —
            الموافقة لا يصنعها إلا المشترك نفسه.
        </p>

        @if (! $consent->isGranted())
            <p class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600" data-testid="already-revoked">
                هذه الموافقة مسحوبة بالفعل. لإعادتها، على المشترك أن يمنحها بنفسه.
            </p>
        @elseif (! $canRevoke)
            <p class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600" data-testid="revoke-forbidden">
                لا تملك صلاحية <span dir="ltr">{{ $consent->capability->operatorPermission()->value }}</span> اللازمة للسحب.
            </p>
        @else
            <div class="grid gap-3 md:grid-cols-3">
                <label class="block text-sm"><span class="text-slate-600">السبب</span>
                    <select wire:model="reasonCode" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="revoke-reason">
                        @foreach ($reasons as $reason)<option value="{{ $reason->value }}">{{ $reason->label() }}</option>@endforeach
                    </select>
                    @error('reasonCode')<span class="text-xs text-rose-700">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm md:col-span-2"><span class="text-slate-600">مرجع الإثبات (اختياري)</span>
                    <input type="text" wire:model="evidence" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" data-testid="revoke-evidence">
                </label>
            </div>
            <p class="mt-2 text-xs text-slate-500">
                سيُرسَل رقم النسخة <span dir="ltr" class="font-mono">{{ $expectedVersion }}</span> مع الطلب: إن تغيّرت الحالة منذ فتح الصفحة، لن يُكتب شيء.
            </p>
            <button type="button" wire:click="revoke" class="mt-3 rounded-lg bg-rose-700 px-4 py-2 text-sm font-medium text-white hover:bg-rose-800" data-testid="revoke-button">
                سحب الموافقة
            </button>
        @endif
    </section>
</div>
