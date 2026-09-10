<!DOCTYPE html>
@php
    $locale = app()->getLocale();
    $dir = data_get(config('sanad.locales'), $locale.'.dir', 'rtl');
    /*
     | Navigation, grouped.
     |
     | Every item now declares the permission that opens its page, and the item
     | is hidden unless the account holds it — a link that only ever produces a
     | 403 is noise, and worse, it tells an operator a page exists that they are
     | not allowed to know about.
     |
     | `legacy => true` mirrors the `permission.legacy:` middleware on the route
     | for pages that PRE-DATE RBAC: a legacy `is_admin` account without a role
     | keeps them exactly as before. The two must agree, or the sidebar and the
     | router would disagree about who may see what — a test asserts they do.
     */
    $navGroups = [
        'التشغيل' => [
            ['route' => 'dashboard', 'label' => 'نظرة عامة', 'icon' => '🏠'],
            ['route' => 'dashboard.launch', 'label' => 'جاهزية الإطلاق V1', 'icon' => '🚦', 'can' => 'launch.readiness.view'],
            ['route' => 'dashboard.whatsapp', 'label' => 'حالة واتساب والطوابير', 'icon' => '🟢', 'can' => 'whatsapp.status.view', 'legacy' => true],
            ['route' => 'dashboard.audit', 'label' => 'سجل التدقيق', 'icon' => '🧾', 'can' => 'audit.view'],
        ],
        'الذكاء والأدوات' => [
            ['route' => 'dashboard.ai.providers', 'label' => 'مزوّدو الذكاء', 'icon' => '🤖', 'can' => 'ai.providers.view'],
            ['route' => 'dashboard.ai.models', 'label' => 'النماذج', 'icon' => '🧠', 'can' => 'ai.models.manage'],
            ['route' => 'dashboard.ai.pricing', 'label' => 'الأسعار', 'icon' => '💵', 'can' => 'ai.pricing.view'],
            ['route' => 'dashboard.ai.routing', 'label' => 'التوجيه', 'icon' => '🧭', 'can' => 'ai.routing.manage'],
            ['route' => 'dashboard.ai.health', 'label' => 'صحة المزوّدين', 'icon' => '🩺', 'can' => 'ai.health.view'],
            ['route' => 'dashboard.ai.cutover', 'label' => 'Cutover', 'icon' => '🔀', 'can' => 'ai.routing.cutover'],
            ['route' => 'dashboard.tools.invocations', 'label' => 'استدعاءات الأدوات', 'icon' => '🛠️', 'can' => 'tools.invocations.view'],
            ['route' => 'dashboard.tools.consents', 'label' => 'موافقات الأدوات', 'icon' => '🔐', 'can' => 'tools.consents.view'],
            ['route' => 'dashboard.persona', 'label' => 'شخصية سَنَد', 'icon' => '🎭', 'can' => 'persona.manage'],
        ],
        'المشتركون' => [
            ['route' => 'dashboard.subscribers', 'label' => 'المشتركون', 'icon' => '👥', 'can' => 'subscribers.view', 'legacy' => true],
            ['route' => 'dashboard.plans', 'label' => 'الباقات', 'icon' => '🏷️', 'can' => 'plans.manage', 'legacy' => true],
            ['route' => 'dashboard.conversations', 'label' => 'المحادثات', 'icon' => '💬', 'can' => 'conversations.view', 'legacy' => true],
            ['route' => 'dashboard.messages', 'label' => 'الرسائل', 'icon' => '✉️', 'can' => 'messages.content.view', 'legacy' => true],
            ['route' => 'dashboard.tasks', 'label' => 'المهام', 'icon' => '✅', 'can' => 'tasks.view', 'legacy' => true],
            ['route' => 'dashboard.reminders', 'label' => 'التذكيرات', 'icon' => '⏰', 'can' => 'reminders.view', 'legacy' => true],
            ['route' => 'dashboard.follow_ups', 'label' => 'المتابعات', 'icon' => '🔁', 'can' => 'follow_ups.view', 'legacy' => true],
            ['route' => 'dashboard.memory', 'label' => 'الذاكرة الدائمة', 'icon' => '🧩', 'can' => 'memory.operations.view'],
        ],
        'المالية' => [
            ['route' => 'dashboard.usage', 'label' => 'الاستخدام', 'icon' => '📊', 'can' => 'usage.view'],
            ['route' => 'dashboard.finance', 'label' => 'المالية', 'icon' => '📈', 'can' => 'finance.view'],
            ['route' => 'dashboard.finance.payments', 'label' => 'المدفوعات', 'icon' => '💳', 'can' => 'finance.payments.manage'],
            ['route' => 'dashboard.finance.refunds', 'label' => 'الاستردادات', 'icon' => '↩️', 'can' => 'finance.payments.manage'],
            ['route' => 'dashboard.finance.cost_invoices', 'label' => 'فواتير التكلفة', 'icon' => '🧾', 'can' => 'finance.reconcile'],
            ['route' => 'dashboard.finance.reconciliation', 'label' => 'تسوية التكلفة', 'icon' => '🧮', 'can' => 'finance.reconcile'],
            ['route' => 'dashboard.finance.fx', 'label' => 'أسعار الصرف', 'icon' => '💱', 'can' => 'finance.fx.manage'],
            ['route' => 'dashboard.finance.close', 'label' => 'إقفال الفترة', 'icon' => '🔒', 'can' => 'finance.view'],
        ],
        'النظام' => [
            ['route' => 'dashboard.settings', 'label' => 'الإعدادات', 'icon' => '⚙️', 'can' => 'settings.manage'],
            ['route' => 'dashboard.expenses', 'label' => 'المصروفات', 'icon' => '💰', 'can' => 'expenses.view', 'legacy' => true],
        ],
    ];

    $navUser = auth()->user();
    $navVisible = function (array $item) use ($navUser): bool {
        if (! isset($item['can'])) {
            return true;
        }

        if ($navUser === null) {
            return false;
        }

        // Mirrors EnsureLegacyAdminOrPermission exactly.
        if (($item['legacy'] ?? false) && $navUser->isAdmin()) {
            return true;
        }

        return $navUser->can($item['can']);
    };

    $navGroups = array_filter(
        array_map(fn (array $items) => array_values(array_filter($items, $navVisible)), $navGroups),
        fn (array $items) => $items !== [],
    );
@endphp
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'لوحة التحكم | سَنَد' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
<div class="flex min-h-screen flex-col md:flex-row">

    {{-- Sidebar (desktop) / top bar (mobile) --}}
    <aside class="w-full shrink-0 border-slate-200 bg-white md:min-h-screen md:w-64 md:border-l">
        <div class="flex items-center justify-between px-5 py-4 md:block">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
                <span class="text-2xl font-extrabold text-emerald-700">سَنَد</span>
                <span class="text-[10px] font-semibold tracking-[0.3em] text-slate-400">SANAD</span>
            </a>
            <form method="POST" action="{{ route('logout') }}" class="md:hidden">
                @csrf
                <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                    خروج
                </button>
            </form>
        </div>

        <nav class="flex gap-1 overflow-x-auto px-3 pb-3 md:flex-col md:overflow-visible md:px-3">
            @foreach ($navGroups as $groupLabel => $items)
                <p class="mt-3 hidden px-3 pb-1 text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400 first:mt-0 md:block">
                    {{ $groupLabel }}
                </p>
                @foreach ($items as $item)
                    @php $active = request()->routeIs($item['route']); @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        @class([
                            'flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition',
                            'bg-emerald-50 text-emerald-700' => $active,
                            'text-slate-600 hover:bg-slate-50' => ! $active,
                        ])
                    >
                        <span>{{ $item['icon'] }}</span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endforeach
        </nav>

        {{-- User + logout (desktop) --}}
        <div class="hidden border-t border-slate-100 px-5 py-4 md:block">
            <p class="mb-2 truncate text-sm font-medium text-slate-700">{{ auth()->user()?->name }}</p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                    تسجيل الخروج
                </button>
            </form>
        </div>
    </aside>

    {{-- Main content --}}
    <main class="flex-1 px-5 py-6 md:px-8 md:py-8">
        <div class="mx-auto max-w-6xl">
            {{ $slot }}
        </div>
    </main>
</div>

@livewireScripts
</body>
</html>
