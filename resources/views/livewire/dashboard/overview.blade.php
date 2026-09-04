<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">نظرة عامة</h1>
        <p class="mt-1 text-sm text-slate-500">ملخّص سريع لبيانات سَنَد الحالية.</p>
    </header>

    @php
        $cards = [
            ['label' => 'المستخدمون', 'value' => $stats['users'], 'icon' => '👤', 'route' => null],
            ['label' => 'المحادثات', 'value' => $stats['conversations'], 'icon' => '💬', 'route' => 'dashboard.conversations'],
            ['label' => 'الرسائل', 'value' => $stats['messages'], 'icon' => '✉️', 'route' => 'dashboard.messages'],
            ['label' => 'مهام غير مكتملة', 'value' => $stats['tasks'], 'icon' => '✅', 'route' => 'dashboard.tasks'],
            ['label' => 'تذكيرات مستحقّة', 'value' => $stats['reminders'], 'icon' => '⏰', 'route' => 'dashboard.reminders'],
            ['label' => 'المصروفات', 'value' => $stats['expenses'], 'icon' => '💰', 'route' => 'dashboard.expenses'],
        ];
    @endphp

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($cards as $card)
            @php $tag = $card['route'] ? 'a' : 'div'; @endphp
            <{{ $tag }}
                @if ($card['route']) href="{{ route($card['route']) }}" wire:navigate @endif
                class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition {{ $card['route'] ? 'hover:border-emerald-300 hover:shadow' : '' }}"
            >
                <div>
                    <p class="text-sm text-slate-500">{{ $card['label'] }}</p>
                    <p class="mt-1 text-3xl font-bold text-slate-800">{{ number_format((float) $card['value']) }}</p>
                </div>
                <span class="text-3xl">{{ $card['icon'] }}</span>
            </{{ $tag }}>
        @endforeach
    </div>

    <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-800">
        لعرض حالة تكامل واتساب وHorizon والطوابير، انتقل إلى
        <a href="{{ route('dashboard.whatsapp') }}" wire:navigate class="font-semibold underline">صفحة حالة واتساب</a>.
    </div>
</div>
