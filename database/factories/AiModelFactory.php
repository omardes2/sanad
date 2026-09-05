<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $externalId = fake()->unique()->lexify('model-????');

        return [
            'provider_id' => AiProvider::factory(),
            'external_id' => $externalId,
            'name' => $externalId,
            'aliases' => [],
            'capabilities' => ['chat'],
            'supports_tools' => true,
            'context_window' => null,
            'max_output_tokens' => null,
            'is_enabled' => true,
            'priority' => 0,
            'fallback_model_id' => null,
            'metadata' => null,
        ];
    }
}
