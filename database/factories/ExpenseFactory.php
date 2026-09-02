<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => fake()->randomFloat(2, 5, 500),
            'currency' => config('sanad.default_currency', 'ILS'),
            'category' => fake()->randomElement(['groceries', 'transport', 'utilities', 'dining', 'other']),
            'merchant' => fake()->company(),
            'expense_date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'notes' => fake()->optional()->sentence(),
            'source_message_id' => null,
        ];
    }
}
