{{-- Shared: problems + typed routing-change confirmation (HandlesCatalogWrites) --}}
@if ($problems !== [])
    <div class="mb-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        <ul class="list-inside list-disc space-y-1">
            @foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach
        </ul>
    </div>
@endif
@if ($confirmPrompt)
    <div class="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p class="font-semibold">هذا التغيير يغيّر التوجيه الفعلي لعملية chat.</p>
        <p class="mt-1">من <code dir="ltr" class="rounded bg-white px-1">{{ $confirmPrompt['before'] ?? 'لا شيء' }}</code> إلى <code dir="ltr" class="rounded bg-white px-1">{{ $confirmPrompt['after'] ?? 'لا شيء' }}</code>.</p>
        <p class="mt-1">اكتب <code dir="ltr" class="rounded bg-white px-1">{{ $confirmPrompt['expected'] }}</code> ثم اضغط حفظ مرة أخرى للتأكيد.</p>
        <input type="text" wire:model="confirmation" dir="ltr" placeholder="{{ $confirmPrompt['expected'] }}" class="mt-2 w-full rounded-lg border-amber-300 text-sm">
        <button type="button" wire:click="cancelConfirmation" class="mt-2 text-xs text-amber-800 underline">إلغاء</button>
    </div>
@endif
