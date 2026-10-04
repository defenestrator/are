<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * reply_sent_at is PostChatReply's claim on a message's reply: set
     * atomically before posting, so a retried or duplicated job never posts
     * the same reply twice (#89).
     */
    public function up(): void
    {
        Schema::table('chat_command_runs', function (Blueprint $table) {
            $table->timestamp('reply_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_command_runs', function (Blueprint $table) {
            $table->dropColumn('reply_sent_at');
        });
    }
};
