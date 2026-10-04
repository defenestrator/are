<?php

namespace App\Providers;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // /go/{code} is public. Viewers click a link once or twice, so this only
        // stops loops; which hits count as clicks is ShortLink::recordClick's job.
        RateLimiter::for('short-links', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));

        // Every OBS overlay load is one exchange, and "Refresh browser when scene
        // becomes active" makes fast scene switching reload sources often, so each
        // overlay gets its own budget per address. A 256-bit token can't be
        // guessed either way; this only stops floods.
        RateLimiter::for('overlay-session', fn (Request $request) => Limit::perMinute(30)
            ->by($request->ip().'|'.$this->overlayName($request)));
    }

    private function overlayName(Request $request): string
    {
        $overlay = $request->route('overlay');

        return $overlay instanceof \BackedEnum ? (string) $overlay->value : (string) $overlay;
    }
}
