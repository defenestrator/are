<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The VTuber agent bridge (#10): the agents that hold API tokens, the
 * questions they claim and answer, and a log of every request they make.
 * Also lets bus ballots come from an agent rather than a chat platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        // An Orkestera-driven VTuber. Its Sanctum tokens hang off this row.
        // user_id is its service account: it votes on the Chat Control Bus
        // as one person, like anyone else.
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // A question the agent took, why, and what it said. The question
        // text is copied so the record survives the question being deleted.
        Schema::create('agent_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('question_text', 500);
            $table->string('reason', 500);
            $table->timestamp('claimed_at');
            $table->text('answer')->nullable();
            // The verdict of the Orkestera workflow's output moderation,
            // which runs before TTS: allowed, flagged or blocked.
            $table->string('moderation_verdict', 16)->nullable();
            $table->json('moderation')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'answered_at']);
        });

        // Every request an agent token makes, and what ARE answered.
        Schema::create('agent_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('method', 8);
            $table->string('path', 255);
            $table->string('route', 64)->nullable();
            $table->unsignedSmallInteger('status');
            // Why ARE refused it, if it did: killed, paused, disabled,
            // unauthenticated, forbidden, throttled.
            $table->string('refused', 32)->nullable();
            $table->text('request')->nullable();
            $table->text('response')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agent_id', 'id']);
        });

        Schema::table('bus_ballots', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('provider', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Agent ballots have no chat platform; the old schema requires one.
        DB::table('bus_ballots')->whereNull('provider')->update(['provider' => 'agent']);

        Schema::table('bus_ballots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
            $table->string('provider', 32)->nullable(false)->change();
        });

        Schema::dropIfExists('agent_requests');
        Schema::dropIfExists('agent_claims');
        Schema::dropIfExists('agents');
    }
};
