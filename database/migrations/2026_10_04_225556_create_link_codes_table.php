<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time codes a signed-in user types into chat (`!link CODE`) to link
     * the chat account they type it from. Typing the code only proposes the
     * link; the code's owner confirms it in Settings. Only an HMAC of the
     * code is stored.
     */
    public function up(): void
    {
        Schema::create('link_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            // Set by `!link CODE`: the chat account waiting for the owner to
            // confirm it in Settings. Nothing is linked until they do.
            $table->string('pending_provider', 32)->nullable();
            $table->string('pending_provider_user_id')->nullable();
            $table->string('pending_name')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_codes');
    }
};
