<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many chat replies ARE has posted into this live chat. Each costs 50
     * quota units, so PostChatReply stops at chat.replies.youtube_per_stream.
     */
    public function up(): void
    {
        Schema::table('youtube_live_chats', function (Blueprint $table) {
            $table->unsignedInteger('replies_sent')->default(0)->after('consecutive_errors');
        });
    }

    public function down(): void
    {
        Schema::table('youtube_live_chats', function (Blueprint $table) {
            $table->dropColumn('replies_sent');
        });
    }
};
