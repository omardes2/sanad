@if ($up)
    <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700">
        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
        متصل
    </span>
@else
    <span class="inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1 text-sm font-medium text-rose-700">
        <span class="h-2 w-2 rounded-full bg-rose-500"></span>
        غير متصل
    </span>
@endif
