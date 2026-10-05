<?php

namespace App\Providers;

use App\Http\Middleware\EnsureNotBanned;
use App\Models\Lead;
use App\Models\User;
use App\YouTube\AnalyticsTokens;
use App\YouTube\StoredAnalyticsTokens;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Channel owners' Google tokens for YouTube Analytics (#12), from the
        // broadcaster Google connection (#126).
        $this->app->bind(AnalyticsTokens::class, StoredAnalyticsTokens::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The web vote queue is one queue shared by every served channel
        // (topics and questions have no channel), so a moderator of any
        // served channel moderates it.
        Gate::define('moderate', fn (User $user) => $user->isAdminUser() && ! $user->isBanned());

        // Acting on one channel, such as a chat command or a Helix call on
        // it: only that channel's broadcaster or moderators (#96).
        Gate::define('moderateChannel', fn (User $user, string $broadcasterId) => ($user->isBroadcasterOf($broadcasterId) || $user->isModeratorOf($broadcasterId))
            && ! $user->isBanned());

        // Any moderator can throw the Chat Control Bus kill switch; only a
        // broadcaster can reset it.
        Gate::define('restoreBus', fn (User $user) => $user->isBroadcaster() && ! $user->isBanned());

        // Livewire actions arrive at /livewire/update, which runs only its
        // persistent middleware. Re-apply the ban check to every action on a
        // page whose route had `not-banned`, so a page opened before a ban
        // cannot keep acting after it.
        Livewire::addPersistentMiddleware([EnsureNotBanned::class]);

        // Lead attribution (#12) is derived from leads, so it is gated by the
        // one broadcaster-only rule for leads, LeadPolicy::viewAny, rather than
        // a copy of it. Moderators are excluded because leads are business PII.
        Gate::define('viewAttribution', fn (User $user) => $user->can('viewAny', Lead::class));

        // The launch readiness page (#135) shows which channels are connected
        // and how the server is set up, so it is for broadcasters, like Horizon.
        Gate::define('viewReadiness', fn (User $user) => $user->isBroadcaster() && ! $user->isBanned());

        // /go/{code} is public. Viewers click a link once or twice, so this only
        // stops loops; which hits count as clicks is ShortLink::recordClick's job.
        RateLimiter::for('short-links', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));

        // The VTuber agent (#10), per token; and the kill-switch endpoint,
        // generous enough that a panicked double-press never fails.
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute((int) config('agent.requests_per_minute'))
            ->by('agent:'.($request->user()?->currentAccessToken()?->getKey() ?? $request->ip())));
        RateLimiter::for('kill-switch', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));

        // Game adapters polling the Chat Control Bus: once a second is plenty.
        RateLimiter::for('bus-adapter', fn (Request $request) => Limit::perMinute(120)
            ->by($request->ip().'|'.(string) $request->route('game')));

        // Every OBS overlay load is one exchange, and "Refresh browser when scene
        // becomes active" makes fast scene switching reload sources often, so each
        // overlay gets its own budget per address. A 256-bit token can't be
        // guessed either way; this only stops floods.
        RateLimiter::for('overlay-session', fn (Request $request) => Limit::perMinute(30)
            ->by($request->ip().'|'.$this->overlayName($request)));

        // POST /music/requests/advance (#136). A player advances once per
        // track, so this only stops a runaway script, and it counts wrong
        // tokens too.
        RateLimiter::for('music-player', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
    }

    private function overlayName(Request $request): string
    {
        $overlay = $request->route('overlay');

        return $overlay instanceof \BackedEnum ? (string) $overlay->value : (string) $overlay;
    }
}
