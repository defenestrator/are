<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\IdentityLinkException;
use App\Http\Controllers\Controller;
use App\Identities;
use App\IdentityProvider;
use App\Jobs\RefreshTwitchSubscriptions;
use App\Models\User;
use App\Twitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as ProviderAccount;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as OAuth2Account;
use Throwable;

/**
 * Sign-in and account linking for every Socialite provider. Both use the
 * provider's one registered callback URL; a session flag set by link() tells
 * the callback which of the two it is finishing.
 */
class SocialiteController extends Controller
{
    private const LINK_INTENT = 'identities.linking';

    public function redirect(IdentityProvider $provider): RedirectResponse
    {
        abort_unless($provider->supportsSignIn(), 404);

        return $this->driver($provider)->scopes($provider->scopes())->redirect();
    }

    public function link(Request $request, IdentityProvider $provider): RedirectResponse
    {
        abort_unless($provider->supportsSignIn(), 404);

        $request->session()->put(self::LINK_INTENT, $provider->value);

        return $this->driver($provider)->scopes($provider->scopes())->redirect();
    }

    public function callback(Request $request, IdentityProvider $provider): RedirectResponse
    {
        abort_unless($provider->supportsSignIn(), 404);

        $user = $request->user();
        $linking = $request->session()->pull(self::LINK_INTENT) === $provider->value;

        if ($user instanceof User) {
            return $linking ? $this->finishLink($user, $provider) : redirect('/vote');
        }

        try {
            $account = $this->driver($provider)->user();
            $user = Identities::signIn($provider, $account);
        } catch (Throwable $e) {
            report($e);

            return redirect("/?failed_{$provider->value}_login=1");
        }

        // Outside the try: a Helix failure must not cost the viewer their sign-in.
        $this->syncTwitchSubscriptions($provider, $user, $account);

        if ($user->isBanned()) {
            return redirect('/?banned=1');
        }

        Auth::login($user);

        return redirect('/vote');
    }

    private function finishLink(User $user, IdentityProvider $provider): RedirectResponse
    {
        try {
            $account = $this->driver($provider)->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('settings')->with('identity_error', "Linking {$provider->label()} failed. Please try again.");
        }

        try {
            Identities::link($user, $provider, $account);
        } catch (IdentityLinkException $e) {
            return redirect()->route('settings')->with('identity_error', $e->getMessage());
        }

        $this->syncTwitchSubscriptions($provider, $user, $account);

        return redirect()->route('settings')->with('identity_status', "{$provider->label()} account linked.");
    }

    /**
     * A Twitch sub on any served or friend channel sets the user's question
     * limit. This never throws: tiers Helix could not answer stay unknown,
     * and a queued job retries them, so sign-in and linking always finish.
     */
    private function syncTwitchSubscriptions(IdentityProvider $provider, User $user, ProviderAccount $account): void
    {
        if ($provider !== IdentityProvider::Twitch || ! $account instanceof OAuth2Account) {
            return;
        }

        try {
            $unknown = Twitch::syncUserSubscriptions($user, $account->token, (string) $account->getId());
        } catch (Throwable $e) {
            report($e);
            $unknown = ['all'];
        }

        if ($unknown !== []) {
            RefreshTwitchSubscriptions::dispatch($user->id)->delay(now()->addMinute());
        }
    }

    private function driver(IdentityProvider $provider): AbstractProvider
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider->value);

        return $driver;
    }
}
