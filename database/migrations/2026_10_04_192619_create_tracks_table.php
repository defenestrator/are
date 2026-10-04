<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original music catalogue. Paths are relative to config('music.disk').
     */
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('artist');
            $table->string('file_path');
            $table->string('stems_path')->nullable();
            $table->string('content_id_status', 32)->default('not_registered');
            $table->boolean('stream_safe')->default(false);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('attribution')->nullable();
            $table->timestamps();

            $table->index(['stream_safe', 'content_id_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
