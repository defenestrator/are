<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who chatted during a stream, for counting unique chatters (#12). It
     * holds a keyed hash of the Twitch user id, never the id, the name or any
     * message text. One row per chatter per session.
     */
    public function up(): void
    {
        Schema::create('stream_chatters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_session_id')->constrained()->cascadeOnDelete();
            $table->char('chatter_hash', 64);
            $table->timestamp('first_seen_at');

            $table->unique(['stream_session_id', 'chatter_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_chatters');
    }
};
