<?php

namespace App\Providers;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('moderate', fn (User $user) => $user->isAdminUser() && ! $user->isBanned());

        // Lead attribution (#12) is derived from leads, so it is gated by the
        // one broadcaster-only rule for leads, LeadPolicy::viewAny, rather than
        // a copy of it. Moderators are excluded because leads are business PII.
        Gate::define('viewAttribution', fn (User $user) => $user->can('viewAny', Lead::class));
    }
}
