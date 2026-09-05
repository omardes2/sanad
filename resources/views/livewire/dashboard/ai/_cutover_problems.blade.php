@if (($problems[$kind] ?? []) !== [])
    <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        <ul class="list-inside list-disc space-y-1">@foreach ($problems[$kind] as $problem)<li>{{ $problem }}</li>@endforeach</ul>
    </div>
@endif
