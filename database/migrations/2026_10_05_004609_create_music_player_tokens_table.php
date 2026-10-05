<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tokens for local players (an OBS script, a Mac Shortcut) that advance
     * the song request queue through POST /music/requests/advance (#136).
     * Like overlay tokens (#50), only the SHA-256 of the token is kept.
     */
    public function up(): void
    {
        Schema::create('music_player_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_player_tokens');
    }
};
