<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Channel-point redemptions from EventSub, read later by the Chat Control
     * Bus and the VTuber bridge. Viewers are keyed by Twitch user id, because
     * most of them never sign in here. The reward is copied as it was when it
     * was redeemed, because the broadcaster can edit it afterwards.
     */
    public function up(): void
    {
        Schema::create('channel_point_redemptions', function (Blueprint $table) {
            $table->id();
            $table->string('twitch_redemption_id')->unique();
            $table->string('broadcaster_id');
            $table->string('twitch_user_id');
            $table->string('user_login');
            $table->string('user_name');
            $table->string('reward_id');
            $table->string('reward_title');
            $table->unsignedInteger('reward_cost');
            $table->text('reward_prompt')->nullable();
            $table->text('user_input')->nullable();
            $table->string('status');
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->index(['broadcaster_id', 'redeemed_at']);
            $table->index('reward_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_point_redemptions');
    }
};
