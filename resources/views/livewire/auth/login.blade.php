<div class="flex min-h-screen flex-col items-center justify-center gap-8 px-6 py-16">
    <header class="flex flex-col items-center gap-2 text-center">
        <h1 class="text-5xl font-extrabold tracking-tight text-emerald-700">سَنَد</h1>
        <p class="text-sm font-semibold tracking-[0.35em] text-slate-400">SANAD</p>
        <p class="mt-2 text-slate-600">لوحة التحكم — تسجيل الدخول</p>
    </header>

    <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <form wire:submit="login" class="space-y-5">
            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-slate-700">البريد الإلكتروني</label>
                <input
                    id="email"
                    type="email"
                    wire:model="email"
                    autocomplete="username"
                    dir="ltr"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 focus:border-emerald-500 focus:ring-emerald-500"
                >
                @error('email')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-slate-700">كلمة المرور</label>
                <input
                    id="password"
                    type="password"
                    wire:model="password"
                    autocomplete="current-password"
                    dir="ltr"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 focus:border-emerald-500 focus:ring-emerald-500"
                >
                @error('password')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" wire:model="remember" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                تذكّرني
            </label>

            <button
                type="submit"
                class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 font-semibold text-white transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove wire:target="login">دخول</span>
                <span wire:loading wire:target="login">جارٍ التحقق…</span>
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-slate-400">
            لا يوجد تسجيل عام. تُنشأ الحسابات عبر مسؤول النظام فقط.
        </p>
    </section>
</div>
