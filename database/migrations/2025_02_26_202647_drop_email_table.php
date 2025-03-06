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
        // Drop email column from users table
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
