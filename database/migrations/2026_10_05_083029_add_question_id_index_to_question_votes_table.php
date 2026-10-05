<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The primary key is (user_id, question_id), so nothing let a lookup by
     * question use an index: every vote's total, counted under the question's
     * row lock, scanned every vote ever cast (#179). PostgreSQL does not index
     * foreign keys by itself.
     */
    public function up(): void
    {
        Schema::table('question_votes', function (Blueprint $table) {
            $table->index('question_id');
        });
    }

    public function down(): void
    {
        Schema::table('question_votes', function (Blueprint $table) {
            $table->dropIndex(['question_id']);
        });
    }
};
