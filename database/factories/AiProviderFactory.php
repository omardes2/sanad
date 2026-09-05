<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProvider>
 */
class AiProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->lexify('provider-????');

        return [
            'key' => $key,
            'name' => ucfirst($key),
            'driver' => $key,
            'base_url' => null,
            'credentials_ref' => null,
            'capabilities' => ['chat'],
            'is_enabled' => true,
            'is_primary' => false,
            'priority' => 10,
            'metadata' => null,
        ];
    }
}
