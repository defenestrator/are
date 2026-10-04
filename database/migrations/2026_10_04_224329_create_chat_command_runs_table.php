<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per chat message that ran a command, keyed on the platform's
     * message id. ChatCommandRegistry claims the row before running the
     * command, so a redelivered message or a retried chat job never runs a
     * command twice. Rows older than a week are pruned daily.
     */
    public function up(): void
    {
        Schema::create('chat_command_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('message_id');
            $table->string('command', 64);
            $table->string('status', 32)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'message_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_command_runs');
    }
};
