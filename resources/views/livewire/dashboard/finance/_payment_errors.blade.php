{{-- Error kinds kept apart (validation · stale · conflict · rule · duplicate) — the service message verbatim, never a stack trace. --}}
@props(['form'])
@foreach (['validation' => ['Validation', 'bg-rose-50 text-rose-700'], 'stale' => ['STATE CHANGED', 'bg-amber-50 text-amber-900'], 'conflict' => ['IDEMPOTENCY CONFLICT', 'bg-rose-50 text-rose-800'], 'rule' => ['REFUSED BY SERVICE', 'bg-rose-50 text-rose-700'], 'duplicate' => ['DUPLICATE SUBMIT', 'bg-slate-100 text-slate-700']] as $kind => [$label, $classes])
    @error($form.'.'.$kind)
        <p class="mb-2 rounded-lg px-3 py-2 text-sm {{ $classes }}" data-testid="{{ $form }}-error-{{ $kind }}" dir="ltr"><strong>{{ $label }}</strong> · {{ $message }}</p>
    @enderror
@endforeach
