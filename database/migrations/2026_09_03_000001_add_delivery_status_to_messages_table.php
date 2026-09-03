<?php

use App\Enums\MessageDeliveryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds provider delivery tracking for OUTBOUND messages (Sprint 0D). This is
 * kept distinct from `external_message_id` (the INBOUND provider id and its
 * idempotency barrier) so the two provider-id namespaces never collide.
 *
 * `provider_message_id` is the id WhatsApp returns for a message WE sent, and
 * the key WhatsApp status webhooks (sent/delivered/read/failed) reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Provider id of an outbound message (e.g. WhatsApp wamid). Nullable
            // (web replies have none); unique so a status webhook maps to one row.
            $table->string('provider_message_id')->nullable()->unique();

            $table->string('delivery_status')->default(MessageDeliveryStatus::Pending->value);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // Safe provider error code only (e.g. "131047"); never the payload.
            $table->string('delivery_error_code')->nullable();

            $table->index('delivery_status');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['provider_message_id']);
            $table->dropIndex(['delivery_status']);
            $table->dropColumn([
                'provider_message_id',
                'delivery_status',
                'sent_at',
                'delivered_at',
                'read_at',
                'delivery_error_code',
            ]);
        });
    }
};
