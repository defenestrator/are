<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a question was submitted (#12): "web" for the vote page, or the
     * chat platform ("twitch", "youtube", ...) for !q. Null for questions
     * submitted before this column existed, which are reported as unknown.
     */
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('source', 16)->nullable();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn('source');
        });
    }
};
