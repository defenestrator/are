<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 1080x1920 cut of an approved clip, made by FormatClipForShorts
     * (#146). short_signature names the source, trim and mode it was made
     * from, so the job can tell whether the stored cut is still current.
     */
    public function up(): void
    {
        Schema::table('stream_markers', function (Blueprint $table) {
            $table->string('short_file_path')->nullable()->after('portrait_file_path');
            $table->string('short_signature', 64)->nullable()->after('short_file_path');
            $table->unsignedBigInteger('short_bytes')->nullable()->after('short_signature');
            $table->timestamp('formatted_at')->nullable()->after('short_bytes');
            $table->text('format_error')->nullable()->after('formatted_at');
        });
    }

    public function down(): void
    {
        Schema::table('stream_markers', function (Blueprint $table) {
            $table->dropColumn(['short_file_path', 'short_signature', 'short_bytes', 'formatted_at', 'format_error']);
        });
    }
};
