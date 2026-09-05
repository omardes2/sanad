<?php

namespace Database\Factories;

use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['chat', 'transcription', 'embedding']),
            'provider' => 'openai',
            'model' => fake()->randomElement(['gpt-4o-mini', 'whisper-1']),
            'input_units' => fake()->numberBetween(0, 5000),
            'output_units' => fake()->numberBetween(0, 2000),
            'cost' => $cost = fake()->randomFloat(6, 0, 0.5),
            'provider_cost' => $cost,
            'total_cost' => $cost,
            'occurred_at' => now(),
            'metadata' => null,
        ];
    }
}
