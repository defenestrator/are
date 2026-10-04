<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A local ban must outlive the account it was placed on. Each ban keeps a
     * plain copy of the banned user's (provider, provider_user_id) pairs, with
     * no foreign key to identities, so deleting the user (which deletes their
     * identities) cannot take the ban with it. user_bans.user_id becomes
     * nullable and is set to null, not cascaded, when the user is deleted.
     */
    public function up(): void
    {
        Schema::create('user_ban_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_ban_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id');
            $table->timestamps();

            $table->unique(['user_ban_id', 'provider', 'provider_user_id']);
            $table->index(['provider', 'provider_user_id']);
        });

        Schema::table('user_bans', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('user_bans', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // Copy every existing ban's identities, lifted or not, so the record is complete.
        $now = now();
        DB::table('user_bans')
            ->join('identities', 'identities.user_id', '=', 'user_bans.user_id')
            ->select(['user_bans.id as user_ban_id', 'identities.provider', 'identities.provider_user_id'])
            ->orderBy('user_bans.id')
            ->orderBy('identities.id')
            ->chunk(500, function ($rows) use ($now) {
                DB::table('user_ban_identities')->insert($rows->map(fn ($row) => [
                    'user_ban_id' => $row->user_ban_id,
                    'provider' => $row->provider,
                    'provider_user_id' => $row->provider_user_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    /**
     * Restores the cascade. Bans whose user was deleted have no user to point
     * at, and the old schema would have cascaded them away, so they are deleted.
     */
    public function down(): void
    {
        DB::table('user_bans')->whereNull('user_id')->delete();

        Schema::table('user_bans', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('user_bans', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::dropIfExists('user_ban_identities');
    }
};
