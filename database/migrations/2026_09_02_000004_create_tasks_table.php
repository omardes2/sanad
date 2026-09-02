<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default(TaskStatus::Pending->value);
            $table->string('priority')->default(TaskPriority::Normal->value);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Keep the task if its originating message is deleted.
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
