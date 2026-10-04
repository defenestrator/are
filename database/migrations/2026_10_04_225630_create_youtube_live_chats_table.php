<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per YouTube live video whose chat ARE reads. The operator
     * starts one with `php artisan youtube:chat VIDEO_ID`. PollYouTubeLiveChat
     * keeps its place (next_page_token) and schedule (next_poll_at) here, so
     * a lost job can be resumed by the scheduler without missing messages.
     */
    public function up(): void
    {
        Schema::create('youtube_live_chats', function (Blueprint $table) {
            $table->id();
            $table->string('video_id')->unique();
            $table->string('channel_id');
            $table->string('live_chat_id');
            $table->string('title')->nullable();
            $table->string('status', 16);
            $table->text('next_page_token')->nullable();
            $table->unsignedInteger('poll_interval_ms');
            $table->timestamp('next_poll_at')->nullable();
            $table->unsignedInteger('consecutive_errors')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_poll_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_live_chats');
    }
};
