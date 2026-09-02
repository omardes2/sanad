<?php

namespace Database\Factories;

use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Models\ChannelAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelAccount>
 */
class ChannelAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel' => ChannelType::WhatsApp,
            'external_identifier' => '97059'.fake()->unique()->numerify('#######'),
            'display_name' => fake()->name(),
            'metadata' => null,
            'status' => ChannelAccountStatus::Active,
        ];
    }

    public function web(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ChannelType::Web,
            'external_identifier' => fake()->unique()->uuid(),
        ]);
    }
}
