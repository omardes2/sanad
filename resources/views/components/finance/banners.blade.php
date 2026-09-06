@props(['blocking' => [], 'warnings' => [], 'info' => [], 'frozen' => false, 'testid' => 'banners'])
{{-- Shared blocker / warning / info banners (Phase E5.1). The wording is the
     service condition itself (code + detail) — never rephrased, never a number.
     `frozen` marks conditions recorded WITH a historical close (they never change). --}}
@if ($blocking !== [] || $warnings !== [] || $info !== [])
    <div class="mb-4 space-y-1" data-testid="{{ $testid }}" dir="ltr">
        @foreach ($blocking as $item)
            <div class="rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-900" data-banner="blocking">BLOCKING · {{ $item }}</div>
        @endforeach
        @foreach ($warnings as $item)
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-900" data-banner="warning">WARNING · {{ $item }}</div>
        @endforeach
        @foreach ($info as $item)
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-700" data-banner="info">{{ $frozen ? 'FROZEN · ' : 'INFO · ' }}{{ $item }}</div>
        @endforeach
    </div>
@endif
