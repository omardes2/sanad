<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Retain audit trail even after the actor is deleted.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            // Optional polymorphic subject (subject_type + subject_id).
            $table->nullableMorphs('subject');
            $table->json('metadata')->nullable();
            // Audit logs are append-only: created_at only, no updated_at.
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
