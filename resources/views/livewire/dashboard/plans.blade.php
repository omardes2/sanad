<div>
    <header class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">الباقات</h1>
            <p class="mt-1 text-sm text-slate-500">إدارة باقات الاشتراك وحدود الاستخدام — بدون تعديل الكود.</p>
        </div>
        <button wire:click="new" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
            + باقة جديدة
        </button>
    </header>

    @if ($showForm)
        <form wire:submit="save" class="mb-6 grid grid-cols-1 gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">الاسم</label>
                <input wire:model="name" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">المعرّف (slug)</label>
                <input wire:model="slug" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                @error('slug') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">الوصف</label>
                <input wire:model="description" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">السعر</label>
                <input wire:model="price" type="number" step="0.01" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                @error('price') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">العملة</label>
                <input wire:model="currency" dir="ltr" maxlength="3" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                @error('currency') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">دورة الفوترة</label>
                <select wire:model="billing_period" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    @foreach ($periods as $period)
                        <option value="{{ $period->value }}">{{ $period->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">أيام التجربة</label>
                <input wire:model="trial_days" type="number" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">حدّ ردود الذكاء / يوم (فارغ = غير محدود)</label>
                <input wire:model="ai_daily" type="number" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">حدّ ردود الذكاء / شهر (فارغ = غير محدود)</label>
                <input wire:model="ai_monthly" type="number" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">الترتيب</label>
                <input wire:model="sort_order" type="number" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-emerald-600"> مُفعّلة
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="is_default" class="rounded border-slate-300 text-emerald-600"> الباقة الافتراضية (onboarding)
            </label>
            <div class="flex gap-2 md:col-span-2">
                <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">حفظ</button>
                <button type="button" wire:click="$set('showForm', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600">إلغاء</button>
            </div>
        </form>
    @endif

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-right text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">الباقة</th>
                        <th class="px-4 py-3 font-medium">السعر</th>
                        <th class="px-4 py-3 font-medium">ردود/يوم</th>
                        <th class="px-4 py-3 font-medium">ردود/شهر</th>
                        <th class="px-4 py-3 font-medium">تجربة</th>
                        <th class="px-4 py-3 font-medium">الحالة</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($plans as $plan)
                        @php $limit = $plan->limitFor(App\Enums\UsageDimension::AiReply); @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-800">{{ $plan->name }}
                                    @if ($plan->is_default) <span class="mr-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] text-emerald-700">افتراضية</span> @endif
                                </div>
                                <div class="font-mono text-xs text-slate-400">{{ $plan->slug }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ number_format((float) $plan->price, 2) }} {{ $plan->currency }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $limit['daily'] ?? '∞' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $limit['monthly'] ?? '∞' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $plan->trial_days }} يوم</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-medium',
                                    'bg-emerald-50 text-emerald-700' => $plan->is_active,
                                    'bg-slate-100 text-slate-500' => ! $plan->is_active,
                                ])>{{ $plan->is_active ? 'مفعّلة' : 'متوقفة' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex gap-3">
                                    <button wire:click="edit({{ $plan->id }})" class="text-emerald-700 hover:underline">تعديل</button>
                                    <button wire:click="toggleActive({{ $plan->id }})" class="text-slate-500 hover:underline">
                                        {{ $plan->is_active ? 'إيقاف' : 'تفعيل' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">لا توجد باقات بعد. أنشئ أول باقة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
