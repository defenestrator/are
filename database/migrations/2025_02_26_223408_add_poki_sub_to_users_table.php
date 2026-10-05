<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('poki_sub')->default('0000');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // A later migration already drops this column.
        if (Schema::hasColumn('users', 'poki_sub')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('poki_sub');
            });
        }
    }
};
