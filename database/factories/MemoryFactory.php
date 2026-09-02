<?php

namespace Database\Factories;

use App\Models\Memory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Memory>
 */
class MemoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => fake()->randomElement(['preference', 'fact', 'contact', 'habit']),
            'content' => fake()->sentence(),
            'importance' => fake()->numberBetween(1, 5),
            'source_message_id' => null,
            'metadata' => null,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
