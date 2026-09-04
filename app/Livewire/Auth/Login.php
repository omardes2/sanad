<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Secure email/password login for the operator dashboard.
 *
 * There is no public registration; accounts are created only via the
 * `sanad:make-admin` console command. Brute force is throttled per
 * email+IP. On success the session id is regenerated to prevent fixation,
 * and only the credentials are used — the password is never stored on the
 * component or logged.
 */
#[Title('تسجيل الدخول | سَنَد')]
#[Layout('components.layouts.app')]
class Login extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('بيانات الدخول غير صحيحة.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        // Prevent session fixation after a privilege change.
        session()->regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), maxAttempts: 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('محاولات كثيرة جدًا. حاول مرة أخرى بعد :seconds ثانية.', ['seconds' => $seconds]),
        ]);
    }

    private function throttleKey(): string
    {
        return mb_strtolower($this->email).'|'.request()->ip();
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
