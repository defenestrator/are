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
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('destination', 2048);
            $table->string('utm_source', 100);
            $table->string('utm_medium', 100);
            $table->string('utm_campaign', 150);
            $table->string('utm_content', 150)->nullable();
            // sha256 of (destination, utm_*): one tuple, one code, even under concurrent ShortLink::for() calls.
            $table->char('tuple_hash', 64)->unique();
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
