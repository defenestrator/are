<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * #11 slice 2: the clip files fetched to the private clips disk, and the
     * current review state (each decision is also kept in clip_decisions).
     */
    public function up(): void
    {
        Schema::table('stream_markers', function (Blueprint $table) {
            // When Create Clip From VOD was last sent, so a retry can look
            // for a clip that attempt made before asking for another.
            $table->timestamp('clip_attempted_at')->nullable()->after('clip_requested_at');
            $table->decimal('clip_duration_seconds', 4, 1)->nullable()->after('clip_attempted_at');

            // Paths on config('clips.disk'). Never shown or sent to a browser.
            $table->string('landscape_file_path')->nullable();
            $table->string('portrait_file_path')->nullable();
            $table->unsignedBigInteger('file_bytes')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->text('fetch_error')->nullable();

            $table->string('review_status', 16)->default('pending');
            $table->string('title', 100)->nullable();
            $table->decimal('trim_start_seconds', 4, 1)->nullable();
            $table->decimal('trim_end_seconds', 4, 1)->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->index('review_status');
        });
    }

    public function down(): void
    {
        Schema::table('stream_markers', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn([
                'clip_attempted_at',
                'clip_duration_seconds',
                'landscape_file_path',
                'portrait_file_path',
                'file_bytes',
                'fetched_at',
                'fetch_error',
                'review_status',
                'title',
                'trim_start_seconds',
                'trim_end_seconds',
                'reviewed_at',
            ]);
        });
    }
};
