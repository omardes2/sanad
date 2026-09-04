<!DOCTYPE html>
@php
    $locale = app()->getLocale();
    $dir = data_get(config('sanad.locales'), $locale.'.dir', 'rtl');
    $nav = [
        ['route' => 'dashboard', 'label' => 'نظرة عامة', 'icon' => '🏠'],
        ['route' => 'dashboard.conversations', 'label' => 'المحادثات', 'icon' => '💬'],
        ['route' => 'dashboard.messages', 'label' => 'الرسائل', 'icon' => '✉️'],
        ['route' => 'dashboard.tasks', 'label' => 'المهام', 'icon' => '✅'],
        ['route' => 'dashboard.reminders', 'label' => 'التذكيرات', 'icon' => '⏰'],
        ['route' => 'dashboard.expenses', 'label' => 'المصروفات', 'icon' => '💰'],
        ['route' => 'dashboard.subscribers', 'label' => 'المشتركون', 'icon' => '👥'],
        ['route' => 'dashboard.plans', 'label' => 'الباقات', 'icon' => '🏷️'],
        ['route' => 'dashboard.whatsapp', 'label' => 'حالة واتساب', 'icon' => '🟢'],
    ];
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
            @foreach ($nav as $item)
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
