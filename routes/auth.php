<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use App\Twitch;

Route::middleware('guest')->group(function () {
    Route::get("login", function() {
        return Socialite::driver("twitch")->scopes([
            "user:read:chat",
            "user:read:email",
            "user:read:subscriptions",
        ])->redirect();
    })->name("login");

    Route::get("twitch/auth", function() {
        // TODO: catch the exception if the user denies the request
        // TJ WRITE UP A USER STORY FOR THIS
        // TJ WRITE UP A USER STORY FOR THIS
        // TJ WRITE UP A USER STORY FOR THIS
        // TJ WRITE UP A USER STORY FOR THIS
        // TJ PERFORM A RISK ASSESSMENT FOR THIS
        // TJ PERFORM QUALITY ASSURANCE FOR THIS
        // TJ PERFORM QUALITAIVE TESTING FOR THIS
        $twitchUser = Socialite::driver("twitch")->user();
        $twitchSub = Twitch::checkUserSubscription(
            $twitchUser->token,
            env("TWITCH_CHANNEL_ID"),
            $twitchUser->id,
        );

        $user = User::updateOrCreate([
            "twitch_id" => $twitchUser->id,
        ], [
            'name' => $twitchUser->name,
            'email' => $twitchUser->email,
            'twitch_id' => $twitchUser->id,
            'twitch_access_token' => $twitchUser->token,
            'twitch_refresh_token' => $twitchUser->refreshToken,
            'twitch_expires_in' => $twitchUser->expiresIn,
            'twitch_avatar_url' => $twitchUser->avatar,
            'twitch_subscription' => $twitchSub,
        ]);

        Auth::login($user);

        return redirect('/vote');
    });


});

Route::post('logout', App\Livewire\Actions\Logout::class)
    ->name('logout');
