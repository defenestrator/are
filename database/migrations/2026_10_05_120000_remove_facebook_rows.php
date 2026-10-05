<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Facebook sign-in is gone, so 'facebook' is no longer an IdentityProvider.
     * A row still holding it would fail to hydrate, so remove any left in a
     * development database. Production had none when this shipped.
     */
    public function up(): void
    {
        DB::table('user_ban_identities')->where('provider', 'facebook')->delete();
        DB::table('identities')->where('provider', 'facebook')->delete();
        DB::table('bus_ballots')->where('provider', 'facebook')->delete();
        DB::table('chat_command_runs')->where('provider', 'facebook')->delete();
        DB::table('link_codes')->where('pending_provider', 'facebook')->delete();
    }

    /**
     * The deleted rows are not restored.
     */
    public function down(): void {}
};
