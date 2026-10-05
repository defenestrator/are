<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily YouTube Analytics figures per channel (#12), from reports.query's
     * "user activity by subscribed status" report, one row per channel, day,
     * content type and subscribed status. FetchYouTubeAnalytics re-fetches a
     * trailing window every day and upserts, because YouTube revises recent
     * days. youtube_analytics_syncs records, per channel, the last day YouTube
     * has reported, so a range is never shown as complete when it is not.
     */
    public function up(): void
    {
        Schema::create('youtube_channel_days', function (Blueprint $table) {
            $table->id();
            $table->string('channel_id');
            $table->date('day');
            $table->string('content_type', 32); // LIVE_STREAM, VIDEO_ON_DEMAND, SHORTS, STORY, UNSPECIFIED
            $table->string('subscribed_status', 16); // SUBSCRIBED, UNSUBSCRIBED
            $table->unsignedBigInteger('views');
            $table->unsignedBigInteger('engaged_views');
            $table->unsignedBigInteger('estimated_minutes_watched');
            $table->unsignedInteger('average_view_duration'); // seconds, as YouTube reports it

            $table->unique(['channel_id', 'day', 'content_type', 'subscribed_status'], 'youtube_channel_days_unique');
            $table->index(['day', 'channel_id']);
        });

        Schema::create('youtube_analytics_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('channel_id')->unique();
            $table->date('reported_through')->nullable();
            $table->timestamp('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_analytics_syncs');
        Schema::dropIfExists('youtube_channel_days');
    }
};
