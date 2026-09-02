<?php

declare(strict_types=1);

use App\Enums\ReplyMode;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a user with an E.164 phone number', function () {
    $user = User::factory()->create(['phone' => '+970599123456']);

    expect($user->phone)->toBe('+970599123456')
        ->and($user->fresh()->phone)->toBe('+970599123456');
});

it('applies SANAD defaults for timezone, locale and currency', function () {
    $user = User::create(['name' => 'بدون تفضيلات', 'phone' => '+970599000123']);
    $user->refresh();

    expect($user->timezone)->toBe(config('sanad.default_user_timezone'))
        ->and($user->locale)->toBe('ar')
        ->and($user->currency)->toBe('ILS')
        ->and($user->preferred_reply_mode)->toBe(ReplyMode::Auto)
        ->and($user->status)->toBe(UserStatus::Pending);
});

it('prevents duplicate phone numbers', function () {
    User::factory()->create(['phone' => '+970599111111']);

    expect(fn () => User::factory()->create(['phone' => '+970599111111']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('prevents duplicate emails', function () {
    User::factory()->create(['email' => 'dup@sanad.test']);

    expect(fn () => User::factory()->create(['email' => 'dup@sanad.test']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows multiple users with a null phone', function () {
    User::factory()->create(['phone' => null]);
    User::factory()->create(['phone' => null]);

    expect(User::whereNull('phone')->count())->toBe(2);
});
