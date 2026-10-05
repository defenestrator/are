<?php

namespace App\Http\Controllers\Twitch;

use App\Http\Controllers\Controller;
use App\Models\BroadcasterToken;
use App\Twitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

class BroadcasterConnectionController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isBroadcaster(), 403);

        return $this->provider()->setScopes(Twitch::BROADCASTER_SCOPES)->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isBroadcaster(), 403);

        /** @var User $twitchUser */
        $twitchUser = $this->provider()->user();

        // The Twitch account that granted access must be the signed-in broadcaster,
        // so one broadcaster cannot attach a token to another's channel.
        abort_unless($twitchUser->getId() === $request->user()->twitch_id, 403);

        BroadcasterToken::updateOrCreate(
            ['broadcaster_id' => $twitchUser->getId()],
            [
                'access_token' => $twitchUser->token,
                'refresh_token' => $twitchUser->refreshToken,
                'expires_at' => now()->addSeconds($twitchUser->expiresIn),
                'scopes' => $twitchUser->approvedScopes,
            ],
        );

        try {
            Twitch::syncModeration($twitchUser->getId());
        } catch (\Throwable $e) {
            report($e);

            return redirect('/vote')->with('status', 'Channel connected, but syncing bans and moderators failed. It will retry on schedule.');
        }

        return redirect('/vote')->with('status', 'Channel connected.');
    }

    private function provider(): AbstractProvider
    {
        /** @var AbstractProvider $provider */
        $provider = Socialite::driver('twitch');

        return $provider->redirectUrl(
            config('services.twitch.broadcaster_redirect') ?: route('twitch.broadcaster.callback')
        );
    }
}
