<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the login page to guests', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSeeLivewire(Login::class)
        ->assertSee('تسجيل الدخول');
});

it('does not expose a public registration route', function () {
    $this->get('/register')->assertNotFound();
});

it('rejects invalid credentials', function () {
    User::factory()->create([
        'email' => 'admin@sanad.test',
        'password' => Hash::make('correct-horse-battery'),
        'is_admin' => true,
    ]);

    Livewire::test(Login::class)
        ->set('email', 'admin@sanad.test')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('logs in an admin with valid credentials and redirects to the dashboard', function () {
    User::factory()->create([
        'email' => 'admin@sanad.test',
        'password' => Hash::make('correct-horse-battery'),
        'is_admin' => true,
    ]);

    Livewire::test(Login::class)
        ->set('email', 'admin@sanad.test')
        ->set('password', 'correct-horse-battery')
        ->set('remember', true)
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

it('throttles repeated failed login attempts', function () {
    User::factory()->create([
        'email' => 'admin@sanad.test',
        'password' => Hash::make('correct-horse-battery'),
        'is_admin' => true,
    ]);

    $component = Livewire::test(Login::class)
        ->set('email', 'admin@sanad.test')
        ->set('password', 'wrong-password');

    // Five failed attempts fill the limiter (5 per key).
    for ($i = 0; $i < 5; $i++) {
        $component->call('login')->assertHasErrors('email');
    }

    // The sixth is refused by the rate limiter before authenticating; even the
    // correct password does not get through while locked out.
    $component->set('password', 'correct-horse-battery')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('logs the user out and invalidates the session', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post('/logout')
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});
