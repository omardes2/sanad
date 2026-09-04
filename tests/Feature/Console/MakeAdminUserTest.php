<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates an admin user with a securely entered password', function () {
    $this->artisan('sanad:make-admin', ['--name' => 'المدير', '--email' => 'admin@sanad.test'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'correct-horse-battery')
        ->assertExitCode(0);

    $admin = User::query()->where('email', 'admin@sanad.test')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->isAdmin())->toBeTrue()
        ->and(Hash::check('correct-horse-battery', $admin->password))->toBeTrue();
});

it('rejects a mismatched password confirmation', function () {
    $this->artisan('sanad:make-admin', ['--name' => 'المدير', '--email' => 'admin@sanad.test'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'different-password')
        ->assertExitCode(1);

    expect(User::query()->where('email', 'admin@sanad.test')->exists())->toBeFalse();
});

it('rejects a password shorter than the minimum', function () {
    $this->artisan('sanad:make-admin', ['--name' => 'المدير', '--email' => 'admin@sanad.test'])
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->assertExitCode(1);

    expect(User::query()->where('email', 'admin@sanad.test')->exists())->toBeFalse();
});

it('promotes an existing user to admin', function () {
    User::factory()->create(['email' => 'existing@sanad.test', 'is_admin' => false]);

    $this->artisan('sanad:make-admin', ['--name' => 'صار مديرًا', '--email' => 'existing@sanad.test'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsQuestion('Confirm password', 'correct-horse-battery')
        ->assertExitCode(0);

    expect(User::query()->where('email', 'existing@sanad.test')->first()->isAdmin())->toBeTrue();
});
