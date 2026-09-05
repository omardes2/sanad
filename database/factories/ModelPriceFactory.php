<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModelPriceSource;
use App\Models\AiModel;
use App\Models\ModelPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelPrice>
 *
 * Test-only convenience: production prices are published through PriceBook,
 * never created directly.
 */
class ModelPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'model_id' => AiModel::factory(),
            'currency' => 'USD',
            'unit' => 'token',
            'input_per_million' => '1.00000000',
            'output_per_million' => '2.00000000',
            'cached_input_per_million' => null,
            'per_request' => '0.00000000',
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'source' => ModelPriceSource::Seed,
            'note' => null,
            'created_by' => null,
        ];
    }
}
