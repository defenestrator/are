<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per platform account. A person is a user; their Twitch, Facebook
     * and YouTube accounts are identities of that user, so votes, question
     * limits and bans count once per person.
     *
     * Back-fills an identity for every user's existing twitch_id and
     * facebook_id. The users columns are dropped by the next migration.
     */
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->text('avatar_url')->nullable();
            // Encrypted with the `encrypted` cast, so they need text, not string.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            // One identity per provider per user, so $user->twitch_id has one answer.
            $table->unique(['user_id', 'provider']);
        });

        $now = now();

        DB::table('users')
            ->select(['id', 'name', 'email', 'twitch_id', 'twitch_avatar_url', 'twitch_token', 'facebook_id', 'facebook_avatar_url', 'created_at'])
            ->orderBy('id')
            ->chunk(500, function ($users) use ($now) {
                $rows = [];

                foreach ($users as $user) {
                    if (filled($user->twitch_id)) {
                        $rows[] = [
                            'user_id' => $user->id,
                            'provider' => 'twitch',
                            'provider_user_id' => (string) $user->twitch_id,
                            'name' => $user->name,
                            'email' => null,
                            'avatar_url' => $user->twitch_avatar_url,
                            'access_token' => filled($user->twitch_token) ? Crypt::encryptString($user->twitch_token) : null,
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'created_at' => $user->created_at ?? $now,
                            'updated_at' => $now,
                        ];
                    }

                    if (filled($user->facebook_id)) {
                        $rows[] = [
                            'user_id' => $user->id,
                            'provider' => 'facebook',
                            'provider_user_id' => (string) $user->facebook_id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'avatar_url' => $user->facebook_avatar_url,
                            'access_token' => null,
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'created_at' => $user->created_at ?? $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('identities')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
