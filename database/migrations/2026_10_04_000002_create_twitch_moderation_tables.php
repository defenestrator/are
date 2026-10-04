<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bans and moderators are keyed by Twitch user id, not users.id, because
     * EventSub reports them for people who may never have signed in here.
     */
    public function up(): void
    {
        Schema::create('twitch_bans', function (Blueprint $table) {
            $table->id();
            $table->string('broadcaster_id');
            $table->string('twitch_user_id');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['broadcaster_id', 'twitch_user_id']);
            $table->index('twitch_user_id');
        });

        Schema::create('twitch_moderators', function (Blueprint $table) {
            $table->id();
            $table->string('broadcaster_id');
            $table->string('twitch_user_id');
            $table->timestamps();

            $table->unique(['broadcaster_id', 'twitch_user_id']);
            $table->index('twitch_user_id');
        });

        Schema::create('broadcaster_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('broadcaster_id')->unique();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->json('scopes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcaster_tokens');
        Schema::dropIfExists('twitch_moderators');
        Schema::dropIfExists('twitch_bans');
    }
};
