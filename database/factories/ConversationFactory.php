<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Keep the channel account owned by the same user as the conversation.
            'channel_account_id' => fn (array $attributes) => ChannelAccount::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'external_conversation_id' => fake()->uuid(),
            'status' => ConversationStatus::Active,
            'last_message_at' => now(),
        ];
    }
}
