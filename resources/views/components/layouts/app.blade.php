<!DOCTYPE html>
@php
    $locale = app()->getLocale();
    $dir = data_get(config('sanad.locales'), $locale.'.dir', 'rtl');
@endphp
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ?? 'سَنَد | SANAD' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    {{ $slot }}

    @livewireScripts
</body>
</html>
