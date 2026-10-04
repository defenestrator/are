<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facebook sign-ins have no Twitch identity, so these columns cannot be required.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('twitch_id')->nullable()->change();
            $table->string('twitch_avatar_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('twitch_id')->nullable(false)->change();
            $table->string('twitch_avatar_url')->nullable(false)->change();
        });
    }
};
