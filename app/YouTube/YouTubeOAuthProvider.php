<?php

namespace App\YouTube;

use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Two\GoogleProvider;

/**
 * Socialite's Google provider, keeping its OAuth state under its own session
 * key. The stock providers all use "state", so connecting a YouTube channel
 * and a Twitch channel in two tabs at once failed one of them (#126 review).
 */
class YouTubeOAuthProvider extends GoogleProvider
{
    public const STATE_KEY = 'youtube_broadcaster_oauth_state';

    public function redirect()
    {
        $state = null;

        if ($this->usesState()) {
            $this->request->session()->put(self::STATE_KEY, $state = $this->getState());
        }

        return new RedirectResponse($this->getAuthUrl($state));
    }

    protected function hasInvalidState()
    {
        if ($this->isStateless()) {
            return false;
        }

        $state = $this->request->session()->pull(self::STATE_KEY);

        return empty($state) || ! hash_equals($state, (string) $this->request->input('state'));
    }
}
