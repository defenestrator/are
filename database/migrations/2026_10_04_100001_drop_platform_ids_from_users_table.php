<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * identities is now the only record of who a user is on each platform.
     * Keeping these columns would leave two sources of truth that drift apart.
     * $user->twitch_id and friends survive as accessors on the User model.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['twitch_id']);
            $table->dropUnique(['facebook_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['twitch_id', 'twitch_avatar_url', 'twitch_token', 'facebook_id', 'facebook_avatar_url']);
        });
    }

    /**
     * Restores the columns from each user's Twitch and Facebook identities.
     * Identities on other providers (YouTube) have no column to return to.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('twitch_id')->nullable()->unique();
            $table->string('twitch_avatar_url')->nullable();
            $table->string('twitch_token')->nullable();
            $table->string('facebook_id')->nullable()->unique();
            $table->string('facebook_avatar_url')->nullable();
        });

        DB::table('identities')
            ->whereIn('provider', ['twitch', 'facebook'])
            ->orderBy('id')
            ->chunk(500, function ($identities) {
                foreach ($identities as $identity) {
                    $columns = $identity->provider === 'twitch'
                        ? [
                            'twitch_id' => $identity->provider_user_id,
                            'twitch_avatar_url' => $identity->avatar_url,
                            'twitch_token' => $this->decrypt($identity->access_token),
                        ]
                        : [
                            'facebook_id' => $identity->provider_user_id,
                            'facebook_avatar_url' => $identity->avatar_url,
                        ];

                    DB::table('users')->where('id', $identity->user_id)->update($columns);
                }
            });
    }

    private function decrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
};
