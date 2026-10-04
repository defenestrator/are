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
     * Outside `local`, only admins may open the dashboard: broadcasters and
     * moderators of a served channel who are not banned. This defers to the
     * `moderate` gate so there is one definition of who counts as an admin.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (User $user) => $user->can('moderate'));
    }
}
