<?php

use App\TwitchSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('twitch_subscription')->default(TwitchSubscription::None)->after('twitch_avatar_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // A later migration already drops this column.
        if (Schema::hasColumn('users', 'twitch_subscription')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('twitch_subscription');
            });
        }
    }
};
