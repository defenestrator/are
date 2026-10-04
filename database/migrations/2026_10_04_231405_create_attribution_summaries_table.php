<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per ISO week whose attribution summary was posted (#12). The
     * unique week is PostWeeklyAttributionSummary's claim, so a re-run or a
     * retried job never posts the same week twice.
     */
    public function up(): void
    {
        Schema::create('attribution_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('iso_week', 8)->unique(); // e.g. 2026-W40
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_summaries');
    }
};
