<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per !clip (#11, slice 1): the Helix stream marker it created and
     * the Twitch clip cut from the VOD around it. A !clip that Twitch refused
     * is kept too, with status marker_failed and the reason, so mods can see
     * why nothing was clipped. See App\Clips\StreamMarkerStatus.
     */
    public function up(): void
    {
        Schema::create('stream_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('broadcaster_id');
            $table->string('twitch_marker_id')->nullable()->unique();
            $table->unsignedInteger('position_seconds')->nullable();
            $table->string('description', 140)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32);
            $table->text('error')->nullable();

            // Create Clip From VOD, Get Clips and Get Clips Download.
            $table->string('vod_id')->nullable();
            $table->string('clip_id')->nullable()->unique();
            $table->string('clip_edit_url')->nullable();
            $table->timestamp('clip_requested_at')->nullable();
            $table->text('landscape_download_url')->nullable();
            $table->text('portrait_download_url')->nullable();
            $table->timestamp('download_urls_expire_at')->nullable();
            $table->timestamps();

            $table->index(['broadcaster_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_markers');
    }
};
