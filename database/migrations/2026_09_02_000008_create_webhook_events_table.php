<?php

use App\Enums\WebhookEventStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider'); // e.g. whatsapp
            $table->string('external_event_id');
            $table->json('payload');
            $table->string('status')->default(WebhookEventStatus::Received->value);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Idempotency: never ingest the same provider event twice.
            $table->unique(['provider', 'external_event_id']);
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
