<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            // Attribute the message to the conversation's owner.
            'user_id' => fn (array $attributes) => Conversation::find($attributes['conversation_id'])->user_id,
            'direction' => MessageDirection::Inbound,
            'type' => MessageType::Text,
            'external_message_id' => 'wamid.'.fake()->unique()->bothify('??##??##??##'),
            'text_content' => fake()->sentence(),
            'media_path' => null,
            'metadata' => null,
            'processing_status' => MessageProcessingStatus::Received,
            'processed_at' => null,
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => MessageDirection::Inbound,
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => MessageDirection::Outbound,
            // Outbound messages are generated locally: no provider id yet.
            'external_message_id' => null,
        ]);
    }
}
