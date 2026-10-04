<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * YouTube Data API quota spent per Pacific Time day, per bucket: "units"
     * (the 10,000-unit default pool) and "search" (the separate 100-call
     * search.list bucket). See App\YouTube\Quota.
     */
    public function up(): void
    {
        Schema::create('youtube_quota_usage', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('bucket', 16);
            $table->unsignedInteger('used')->default(0);
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('failed_calls')->default(0);
            $table->timestamps();

            $table->unique(['day', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_quota_usage');
    }
};
