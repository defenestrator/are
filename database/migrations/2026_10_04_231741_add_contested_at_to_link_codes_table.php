<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set when a second chat account types a code that another account has
     * already claimed. A contested code is void: it cannot be confirmed or
     * claimed again, and its owner is told to get a new one (#102).
     */
    public function up(): void
    {
        Schema::table('link_codes', function (Blueprint $table) {
            $table->timestamp('contested_at')->nullable()->after('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('link_codes', function (Blueprint $table) {
            $table->dropColumn('contested_at');
        });
    }
};
