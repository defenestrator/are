<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A YouTube channel owner's Google OAuth token (scope youtube.force-ssl),
     * granted at /youtube/broadcaster/connect so ARE can post chat replies.
     * Keyed on the YouTube channel id Google reports for the token. Tokens are
     * encrypted at rest by the model's casts.
     */
    public function up(): void
    {
        Schema::create('youtube_channel_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('channel_id')->unique();
            $table->string('channel_title')->nullable();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->json('scopes');
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_channel_tokens');
    }
};
