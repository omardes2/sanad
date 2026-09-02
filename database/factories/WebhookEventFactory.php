<?php

namespace Database\Factories;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'whatsapp',
            'external_event_id' => fake()->unique()->uuid(),
            'payload' => ['object' => 'whatsapp_business_account', 'entry' => []],
            'status' => WebhookEventStatus::Received,
            'received_at' => now(),
            'processed_at' => null,
            'error_message' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebhookEventStatus::Processed,
            'processed_at' => now(),
        ]);
    }
}
