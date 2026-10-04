<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overlay_tokens', function (Blueprint $table) {
            $table->id();
            // One row per overlay (App\Enums\Overlay). Only the SHA-256 of the token is stored.
            $table->string('overlay')->unique();
            $table->char('token_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overlay_tokens');
    }
};
