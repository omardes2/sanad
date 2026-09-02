<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Expense;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a small, self-consistent demo dataset for local development.
 *
 * Uses fake data only — the phone number and identifiers are placeholders,
 * NOT real. Safe to re-run: the demo user is keyed by its phone number.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Demo user (fake E.164 phone — not a real number).
        $user = User::factory()->create([
            'name' => 'مستخدم تجريبي',
            'phone' => '+970599000001',
            'email' => 'demo@sanad.test',
        ]);

        // 2) WhatsApp channel account for the demo user.
        $channelAccount = ChannelAccount::factory()->for($user)->create([
            'channel' => ChannelType::WhatsApp,
            'external_identifier' => '970599000001',
            'display_name' => 'مستخدم تجريبي',
        ]);

        // 3) Conversation.
        $conversation = Conversation::factory()->create([
            'user_id' => $user->id,
            'channel_account_id' => $channelAccount->id,
        ]);

        // 4) Messages (inbound + outbound).
        $inbound = Message::factory()->inbound()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'type' => MessageType::Text,
            'text_content' => 'ذكّرني بشراء الحليب غدًا الساعة 8 مساءً.',
        ]);

        Message::factory()->outbound()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'direction' => MessageDirection::Outbound,
            'type' => MessageType::Text,
            'text_content' => 'تمام! أنشأتُ لك تذكيرًا.',
        ]);

        // 5) Task originating from the inbound message.
        $task = Task::factory()->create([
            'user_id' => $user->id,
            'title' => 'شراء الحليب',
            'status' => TaskStatus::Pending,
            'due_at' => now()->addDay()->setTime(20, 0),
            'source_message_id' => $inbound->id,
        ]);

        // 6) Reminder linked to the task + message.
        Reminder::factory()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'source_message_id' => $inbound->id,
            'title' => 'تذكير: شراء الحليب',
            'remind_at' => now()->addDay()->setTime(20, 0),
            'timezone' => $user->timezone,
            'channel' => ChannelType::WhatsApp,
            'status' => ReminderStatus::Pending,
        ]);

        // 7) Memory.
        Memory::factory()->create([
            'user_id' => $user->id,
            'category' => 'preference',
            'content' => 'يفضّل المستخدم التذكير مساءً.',
            'importance' => 4,
            'source_message_id' => $inbound->id,
        ]);

        // 8) Expenses.
        Expense::factory()->count(3)->create([
            'user_id' => $user->id,
            'currency' => $user->currency,
        ]);
    }
}
