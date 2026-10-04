<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per broadcast, opened by stream.online and closed by
     * stream.offline. Analytics and clips read started_at and ended_at.
     */
    public function up(): void
    {
        Schema::create('stream_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('broadcaster_id');
            $table->string('twitch_stream_id')->unique();
            $table->string('type');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['broadcaster_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_sessions');
    }
};
