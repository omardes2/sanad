<div>
    <header class="mb-6">
        <a href="{{ route('dashboard.subscribers') }}" wire:navigate class="text-sm text-emerald-700 hover:underline">← المشتركون</a>
        <h1 class="mt-2 text-2xl font-bold text-slate-800">{{ $subscriber->name }}</h1>
        <p class="mt-1 font-mono text-xs text-slate-500" dir="ltr">
            {{ $subscriber->phone ? '***'.substr($subscriber->phone, -4) : '—' }}
        </p>
    </header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Subscription summary --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-sm font-bold uppercase tracking-wider text-slate-500">الاشتراك</h2>
            @if ($subscription)
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">الباقة</dt><dd class="font-medium text-slate-800">{{ $subscription->plan?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الحالة</dt><dd><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ $subscription->status->label() }}</span></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">نهاية التجربة</dt><dd class="text-slate-700">{{ $subscription->trial_ends_at?->format('Y-m-d') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">نهاية الفترة</dt><dd class="text-slate-700">{{ $subscription->current_period_end?->format('Y-m-d') ?? 'بدون انتهاء' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">مُخوّل حاليًا</dt><dd>{{ $subscription->isEntitled() ? 'نعم' : 'لا' }}</dd></div>
                </dl>
            @else
                <p class="text-sm text-slate-400">لا يوجد اشتراك — عيّن باقة أدناه.</p>
            @endif
        </section>

        {{-- AI usage --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-sm font-bold uppercase tracking-wider text-slate-500">استخدام ردود الذكاء</h2>
            @php
                $dailyLimit = $entitlement->entitled ? ($entitlement->dailyLimit ?? '∞') : '—';
                $monthlyLimit = $entitlement->entitled ? ($entitlement->monthlyLimit ?? '∞') : '—';
                $dailyRemaining = ($entitlement->entitled && $entitlement->dailyLimit !== null) ? max(0, $entitlement->dailyLimit - $used['daily']) : '∞';
                $monthlyRemaining = ($entitlement->entitled && $entitlement->monthlyLimit !== null) ? max(0, $entitlement->monthlyLimit - $used['monthly']) : '∞';
            @endphp
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">اليوم</dt><dd class="text-slate-800">{{ $used['daily'] }} / {{ $dailyLimit }} <span class="text-slate-400">(متبقٍّ {{ $entitlement->entitled ? $dailyRemaining : '—' }})</span></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">هذا الشهر</dt><dd class="text-slate-800">{{ $used['monthly'] }} / {{ $monthlyLimit }} <span class="text-slate-400">(متبقٍّ {{ $entitlement->entitled ? $monthlyRemaining : '—' }})</span></dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">الإتاحة</dt><dd>{{ $entitlement->entitled ? 'مُتاحة' : 'غير متاحة' }}</dd></div>
            </dl>
            @unless (config('billing.enforce'))
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">تطبيق الحدود مُعطّل حاليًا (BILLING_ENFORCE=false).</p>
            @endunless
        </section>
    </div>

    {{-- Manual admin actions --}}
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-bold uppercase tracking-wider text-slate-500">إجراءات يدوية</h2>
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">تعيين باقة</label>
                <div class="flex gap-2">
                    <select wire:model="planId" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">— اختر —</option>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                    <button wire:click="assignPlan" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">تعيين وتفعيل</button>
                @error('state') <p class="mt-1 text-sm font-semibold text-rose-700" data-testid="stale-state">{{ $message }}</p> @enderror
                </div>
                @error('planId') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">تمديد (أيام)</label>
                <div class="flex gap-2">
                    <input wire:model="extendDays" type="number" dir="ltr" class="w-24 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <button wire:click="extend" class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">تمديد</button>
                </div>
            </div>

            <button wire:click="activate" class="rounded-lg border border-emerald-300 px-3 py-2 text-sm text-emerald-700 hover:bg-emerald-50">تفعيل</button>
            <button wire:click="suspend" class="rounded-lg border border-amber-300 px-3 py-2 text-sm text-amber-700 hover:bg-amber-50">إيقاف</button>
        </div>
    </section>
</div>
