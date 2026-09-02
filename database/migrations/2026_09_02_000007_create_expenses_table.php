<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Money is stored as an exact decimal — never a float.
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('category')->nullable();
            $table->string('merchant')->nullable();
            $table->date('expense_date');
            $table->text('notes')->nullable();
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'expense_date']);
            $table->index(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
