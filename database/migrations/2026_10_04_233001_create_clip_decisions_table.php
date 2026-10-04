<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every approve, reject and edit on a clip, oldest first: the audit trail
     * behind stream_markers.review_status. Nothing publishes from here.
     */
    public function up(): void
    {
        Schema::create('clip_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_marker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision', 16);
            $table->string('title', 100)->nullable();
            $table->decimal('trim_start_seconds', 4, 1)->nullable();
            $table->decimal('trim_end_seconds', 4, 1)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['stream_marker_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_decisions');
    }
};
