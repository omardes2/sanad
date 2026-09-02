@use('App\Enums\MessageDirection')
@use('App\Enums\MessageProcessingStatus')

<div class="mx-auto flex min-h-screen max-w-3xl flex-col gap-4 px-4 py-8">

    <div class="rounded-xl border border-amber-300 bg-amber-50 px-5 py-3 text-center text-sm font-medium text-amber-800">
        🧪 محاكي محادثة محلي (بيئة التطوير فقط) — لا اتصال بـ WhatsApp أو أي خدمة خارجية.
    </div>

    <header class="flex flex-col gap-1 text-center">
        <h1 class="text-3xl font-extrabold text-emerald-700">سَنَد — محاكي المحادثة</h1>
        <p class="text-sm text-slate-500">جرّب مسار الرسائل من طرف إلى طرف عبر قناة Web.</p>
    </header>

    {{-- Demo user picker --}}
    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3">
        <span class="text-sm font-semibold text-slate-600">المستخدم التجريبي:</span>
        @forelse ($users as $user)
            <button type="button" wire:click="selectUser({{ $user->id }})"
                class="rounded-full px-3 py-1 text-sm transition
                    {{ $selectedUserId === $user->id ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                {{ $user->name }}
            </button>
        @empty
            <span class="text-sm text-rose-600">لا يوجد مستخدمون. شغّل <code>php artisan db:seed --class=DemoDataSeeder</code>.</span>
        @endforelse
    </div>

    {{-- Messages --}}
    <div class="flex-1 space-y-3 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-4"
         wire:poll.1500ms>
        @forelse ($messages as $message)
            @php $inbound = $message->direction === MessageDirection::Inbound; @endphp
            <div class="flex {{ $inbound ? 'justify-start' : 'justify-end' }}">
                <div class="max-w-[75%] rounded-2xl px-4 py-2 text-sm
                    {{ $inbound ? 'bg-slate-100 text-slate-800' : 'bg-emerald-600 text-white' }}">
                    <div class="mb-1 text-xs font-semibold opacity-70">
                        {{ $inbound ? 'أنت' : 'سَنَد' }}
                    </div>
                    <div class="whitespace-pre-wrap break-words">{{ $message->text_content }}</div>
                    <div class="mt-1 flex items-center gap-2 text-[11px] opacity-60">
                        <span>{{ $message->created_at?->format('H:i:s') }}</span>
                        @if ($inbound)
                            @switch($message->processing_status)
                                @case(MessageProcessingStatus::Processed)
                                    <span>✓ تمت المعالجة</span> @break
                                @case(MessageProcessingStatus::Failed)
                                    <span class="text-rose-600">✕ فشل</span> @break
                                @default
                                    <span>⏳ في الانتظار…</span>
                            @endswitch
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <p class="py-10 text-center text-sm text-slate-400">لا رسائل بعد — اكتب رسالة للبدء.</p>
        @endforelse
    </div>

    {{-- Composer --}}
    <form wire:submit="send" class="flex items-start gap-2">
        <div class="flex-1">
            <input type="text" wire:model="body" maxlength="2000"
                placeholder="اكتب رسالتك… (جرّب: مرحبا)"
                class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                @disabled($selectedUserId === null)>
            @error('body') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <button type="submit"
            class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
            @disabled($selectedUserId === null)>
            <span wire:loading.remove wire:target="send">إرسال</span>
            <span wire:loading wire:target="send">جارٍ الإرسال…</span>
        </button>
    </form>

    <p class="text-center text-xs text-slate-400">
        يظهر رد سَنَد بعد معالجة الـ Queue — تأكد من تشغيل <code>php artisan horizon</code> أو <code>php artisan queue:work redis</code>.
    </p>
</div>
