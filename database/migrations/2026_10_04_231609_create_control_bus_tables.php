<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Chat Control Bus (#9): chat ballots, the vote windows that tally them,
 * the actions published to game adapters, the moderator controls, and the
 * tokens adapters use to poll when Reverb is unavailable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per game ('orkestera', ...) plus '*' for the whole bus. The
        // kill switch, pause and mode live here, never in a cache: every
        // publish reads them fresh.
        Schema::create('bus_controls', function (Blueprint $table) {
            $table->string('scope', 64)->primary();
            $table->string('active_game', 64)->nullable();
            $table->string('mode', 32)->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('paused_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('killed_at')->nullable();
            $table->foreignId('killed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bus_windows', function (Blueprint $table) {
            $table->id();
            $table->string('game', 64);
            $table->string('mode', 32);
            $table->string('status', 32)->default('open');
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('total_votes')->default(0);
            $table->timestamps();

            $table->index(['game', 'status']);
            $table->index(['status', 'closes_at']);
        });

        // Every chat action, whatever happened to it: the audit log.
        Schema::create('bus_ballots', function (Blueprint $table) {
            $table->id();
            $table->string('game', 64)->nullable();
            $table->foreignId('window_id')->nullable()->constrained('bus_windows')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('message_id')->nullable();
            $table->string('verb', 64)->nullable();
            $table->string('argument', 500)->nullable();
            $table->string('action_key', 600)->nullable();
            $table->unsignedSmallInteger('option_number')->nullable();
            $table->string('status', 32);
            // Cosmetic only: shown as flair, never used as weight.
            $table->boolean('subscriber')->default(false);
            $table->timestamps();

            $table->index(['window_id', 'status']);
            $table->index(['window_id', 'user_id']);
            $table->index(['window_id', 'option_number']);
        });

        // What game adapters receive, over Reverb or by polling.
        Schema::create('bus_publications', function (Blueprint $table) {
            $table->id();
            $table->string('game', 64);
            $table->string('mode', 32);
            $table->foreignId('window_id')->nullable()->constrained('bus_windows')->nullOnDelete();
            $table->foreignId('ballot_id')->nullable()->constrained('bus_ballots')->nullOnDelete();
            $table->string('verb', 64);
            $table->string('argument', 500)->nullable();
            $table->unsignedInteger('votes');
            $table->unsignedInteger('total_votes');
            $table->string('flair', 32)->nullable();
            // First handed to an adapter (a poll or the Reverb send). The kill
            // switch voids everything undelivered, and anything delivered in
            // the last bus.kill_undo_seconds, so adapters can undo it.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('vetoed_at')->nullable();
            $table->foreignId('vetoed_by_id')->nullable()->constrained('users')->nullOnDelete();
            // 'moderator' or 'kill'.
            $table->string('veto_reason', 32)->nullable();
            $table->timestamps();

            $table->index(['game', 'id']);
            $table->index(['game', 'vetoed_at']);
            $table->index(['vetoed_at', 'delivered_at']);
        });

        // Free-text winners (Orkestera's task) wait here for a moderator. Only
        // an approval publishes one, so it gets its publication id, and a
        // place in adapters' cursor order, at that moment.
        Schema::create('bus_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('game', 64);
            $table->string('mode', 32);
            $table->foreignId('window_id')->nullable()->constrained('bus_windows')->nullOnDelete();
            $table->foreignId('ballot_id')->nullable()->constrained('bus_ballots')->nullOnDelete();
            $table->string('verb', 64);
            $table->string('argument', 500)->nullable();
            $table->string('action_key', 600);
            $table->unsignedInteger('votes');
            $table->unsignedInteger('total_votes');
            $table->string('flair', 32)->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('expires_at');
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('publication_id')->nullable()->constrained('bus_publications')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('bus_adapter_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('game', 64)->unique();
            $table->string('token_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_adapter_tokens');
        Schema::dropIfExists('bus_approvals');
        Schema::dropIfExists('bus_publications');
        Schema::dropIfExists('bus_ballots');
        Schema::dropIfExists('bus_windows');
        Schema::dropIfExists('bus_controls');
    }
};
