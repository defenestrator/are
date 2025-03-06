<?php

use App\TwitchSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('sessions')->truncate();

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'poki_sub')) {
                $table->dropColumn('poki_sub');
            }
            if (Schema::hasColumn('users', 'twitch_access_token')) {
                $table->dropColumn('twitch_access_token');
            }
            if (Schema::hasColumn('users', 'twitch_refresh_token')) {
                $table->dropColumn('twitch_refresh_token');
            }
            if (Schema::hasColumn('users', 'twitch_expires_in')) {
                $table->dropColumn('twitch_expires_in');
            }
            if (Schema::hasColumn('users', 'twitch_subscription')) {
                $table->dropColumn('twitch_subscription');
            }
        });

        Schema::create('user_twitch_subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('broadcaster_id');
            $table->string('twitch_subscription')->default(TwitchSubscription::None);
            $table->timestamps();

            $table->primary(['user_id', 'broadcaster_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_twitch_subscriptions');
    }
};
