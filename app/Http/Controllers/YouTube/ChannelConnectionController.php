<?php

namespace App\Http\Controllers\YouTube;

use App\Http\Controllers\Controller;
use App\Models\YouTubeChannelToken;
use App\YouTube\YouTubeApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as OAuthUser;

/**
 * A broadcaster connects a YouTube channel they own, granting youtube.force-ssl
 * so ARE can post chat replies there (#110). Offline access with forced
 * consent, so Google always returns a refresh token.
 *
 * The channel is whatever channel the granting Google account owns
 * (channels.list?mine=true). It must be one of YOUTUBE_CHANNEL_IDS when that
 * is set, so a token for some other channel is never stored.
 */
class ChannelConnectionController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isBroadcaster(), 403);

        return $this->provider()
            ->setScopes(['openid', YouTubeApi::POST_SCOPE])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isBroadcaster(), 403);

        /** @var OAuthUser $account */
        $account = $this->provider()->user();

        if (! in_array(YouTubeApi::POST_SCOPE, $account->approvedScopes, true)) {
            return $this->failed('Google did not grant permission to post in YouTube chat. Connect again and allow it.');
        }

        if (! $account->refreshToken) {
            return $this->failed('Google sent no refresh token. Remove ARE under your Google account\'s third-party access, then connect again.');
        }

        $response = YouTubeApi::myChannel($account->token);
        $channelId = $response->json('items.0.id');

        if ($response->failed() || ! is_string($channelId)) {
            return $this->failed('That Google account has no YouTube channel, or YouTube would not say which. Connect with the account that owns the channel.');
        }

        $allowed = (array) config('services.youtube.channel_ids', []);
        if ($allowed !== [] && ! in_array($channelId, $allowed, true)) {
            return $this->failed("YouTube channel {$channelId} is not one of this app's channels (YOUTUBE_CHANNEL_IDS).");
        }

        YouTubeChannelToken::updateOrCreate(['channel_id' => $channelId], [
            'channel_title' => $response->json('items.0.snippet.title'),
            'access_token' => $account->token,
            'refresh_token' => $account->refreshToken,
            'expires_at' => now()->addSeconds((int) ($account->expiresIn ?: 3600)),
            'scopes' => $account->approvedScopes,
            'connected_by' => $request->user()->id,
        ]);

        return redirect('/vote')->with('status', "YouTube channel {$channelId} connected for chat replies.");
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect('/vote')->with('status', $message);
    }

    private function provider(): AbstractProvider
    {
        /** @var AbstractProvider $provider */
        $provider = Socialite::buildProvider(GoogleProvider::class, [
            'client_id' => config('services.youtube.oauth.client_id'),
            'client_secret' => config('services.youtube.oauth.client_secret'),
            'redirect' => config('services.youtube.oauth.redirect') ?: route('youtube.broadcaster.callback'),
        ]);

        return $provider;
    }
}
