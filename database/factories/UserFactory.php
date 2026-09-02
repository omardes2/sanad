<?php

namespace Database\Factories;

use App\Enums\ReplyMode;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Fake E.164 number (Palestinian mobile shape). Never a real number.
            'phone' => '+97059'.fake()->unique()->numerify('#######'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'timezone' => config('sanad.default_user_timezone'),
            'locale' => config('sanad.default_locale', 'ar'),
            'currency' => config('sanad.default_currency', 'ILS'),
            'preferred_reply_mode' => ReplyMode::Auto,
            'status' => UserStatus::Active,
            'onboarding_completed_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Pending,
            'onboarding_completed_at' => null,
        ]);
    }
}
