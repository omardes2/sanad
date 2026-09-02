<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an explicit self-referential link from an outbound reply to the inbound
 * message it answers, plus a UNIQUE constraint guaranteeing — at the database
 * level — at most one reply per inbound message. This is the primary
 * idempotency barrier for replies (not JSON metadata).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('in_reply_to_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();

            // At most one outbound reply per inbound message. Inbound rows keep
            // this NULL, and multiple NULLs are allowed on both PostgreSQL and
            // SQLite, so this does not constrain inbound messages.
            $table->unique('in_reply_to_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['in_reply_to_message_id']);
            $table->dropConstrainedForeignId('in_reply_to_message_id');
        });
    }
};
