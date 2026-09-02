<?php

use App\Enums\ReminderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('title');
            $table->timestamp('remind_at'); // stored in UTC
            $table->string('timezone'); // the user's timezone at scheduling time
            $table->string('channel'); // App\Enums\ChannelType
            $table->string('status')->default(ReminderStatus::Pending->value);
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            // Scheduler lookup: fetch due reminders by status + remind_at.
            $table->index(['status', 'remind_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
