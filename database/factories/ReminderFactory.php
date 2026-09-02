<?php

namespace Database\Factories;

use App\Enums\ChannelType;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'task_id' => null,
            'source_message_id' => null,
            'title' => fake()->sentence(3),
            'remind_at' => now()->addHours(fake()->numberBetween(1, 48)),
            'timezone' => config('sanad.default_user_timezone'),
            'channel' => ChannelType::WhatsApp,
            'status' => ReminderStatus::Pending,
            'sent_at' => null,
            'attempts' => 0,
            'last_error' => null,
        ];
    }

    /**
     * A reminder whose time has already passed and is still pending (i.e. due).
     */
    public function due(): static
    {
        return $this->state(fn (array $attributes) => [
            'remind_at' => now()->subMinutes(5),
            'status' => ReminderStatus::Pending,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReminderStatus::Sent,
            'sent_at' => now(),
        ]);
    }
}
