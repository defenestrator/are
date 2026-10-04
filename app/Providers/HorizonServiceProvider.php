<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * Outside `local`, only the broadcasters of served channels may open the
     * dashboard. It shows every job's payload and can retry or delete failed
     * jobs, so it is an operator tool. Moderators are community volunteers
     * and deliberately do not get it, even though they pass `moderate`.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (User $user) => $user->isBroadcaster() && ! $user->isBanned());
    }
}
