<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clip_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_marker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->char('clip_revision', 64);
            $table->string('disk');
            $table->text('file_path');
            $table->char('artifact_sha256', 64);
            $table->char('render_signature', 64);
            $table->string('policy_revision', 100);
            $table->string('verdict', 16);
            $table->json('checks');
            $table->string('status', 16)->default('pending');
            $table->string('error', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['agent_id', 'request_id']);
            $table->index(['stream_marker_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_releases');
    }
};
