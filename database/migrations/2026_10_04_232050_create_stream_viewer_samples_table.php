<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Concurrent viewers of a live stream, sampled from Helix Get Streams every
     * few minutes (#12). One sample per session per minute: the unique index
     * makes a duplicated sampling job harmless.
     */
    public function up(): void
    {
        Schema::create('stream_viewer_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_session_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->unsignedInteger('viewer_count');

            $table->unique(['stream_session_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_viewer_samples');
    }
};
