<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Song requests from !song and the channel-point reward. The requester is
     * nullable because a channel-point redeemer need not have signed in here;
     * requester_name keeps the display name either way. A redemption makes at
     * most one request (the unique key), so a redelivered event cannot queue
     * the song twice.
     */
    public function up(): void
    {
        Schema::create('song_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name');
            $table->string('source', 32);
            $table->foreignId('channel_point_redemption_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['track_id', 'status']);
            $table->index(['requester_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_requests');
    }
};
