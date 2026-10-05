<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clip file retention (#143). clips:prune-files deletes a decided clip's
     * files after a while and sets files_pruned_at; the row stays.
     * published_at is set by the Shorts upload (a later slice of #11); an
     * approved clip is kept until then, or for clips.keep_approved_days.
     */
    public function up(): void
    {
        Schema::table('stream_markers', function (Blueprint $table) {
            $table->timestamp('files_pruned_at')->nullable()->after('fetch_error');
            $table->timestamp('published_at')->nullable()->after('reviewed_at');

            $table->index(['review_status', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stream_markers', function (Blueprint $table) {
            $table->dropIndex(['review_status', 'reviewed_at']);
            $table->dropColumn(['files_pruned_at', 'published_at']);
        });
    }
};
