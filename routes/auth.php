<?php

use App\Models\User;
use App\Models\UserTwitchSubscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use App\Twitch;
use App\Http\Controllers\Twitch\BroadcasterConnectionController;
use App\Http\Controllers\Twitch\EventSubController;

Route::middleware('guest')->group(function () {
    Route::get("login", function () {
        return Socialite::driver("twitch")->scopes([
            "user:read:chat",
            "user:read:subscriptions",
        ])->redirect();
    })->name("login");

    Route::get("login/facebook", function () {
        return Socialite::driver("facebook")->redirect();
    })->name("login.facebook");

    Route::get("auth/facebook/callback", function () {
        try {
            $facebookUser = Socialite::driver("facebook")->user();

            $user = User::updateOrCreate([
                "facebook_id" => $facebookUser->id,
            ], [
                'name' => $facebookUser->name,
                'email' => $facebookUser->email,
                'facebook_avatar_url' => $facebookUser->avatar,
            ]);

            Auth::login($user);
        } catch (\Exception $e) {
            return redirect('/?failed_facebook_login=1');
        }

        return redirect('/vote');
    });

    Route::get("twitch/auth", function () {
        try {
            $twitchUser = Socialite::driver("twitch")->user();

            $user = User::updateOrCreate([
                "twitch_id" => $twitchUser->id,
            ], [
                'name' => $twitchUser->name,
                'twitch_avatar_url' => $twitchUser->avatar,
            ]);

            $subscriptions = Twitch::checkUserSubscriptions(
                $twitchUser->token,
                User::getAllFriendIDs(),
                $twitchUser->id,
            );

            $subscriptions->each(
                fn($subscription, $broadcaster_id) =>
                UserTwitchSubscription::updateOrCreate([
                    "user_id" => $user->id,
                    "broadcaster_id" => $broadcaster_id,
                ], [
                    "twitch_subscription" => $subscription,
                ])
            );

            if ($user->isBanned()) {
                return redirect('/?banned=1');
            }

            Auth::login($user);
        } catch (\Exception $e) {
            return redirect('/?failed_twitch_login=1');
        }

        return redirect('/vote');
    });
});

Route::post('logout', App\Livewire\Actions\Logout::class)
    ->name('logout');

Route::get('logout', App\Livewire\Actions\Logout::class)
    ->name('getLogout');

// A broadcaster grants this app moderation and channel scopes, so it can sync
// bans and moderators and set the stream title. Register the callback URL in
// the Twitch developer console alongside the login callback.
Route::middleware('auth')->group(function () {
    Route::get('twitch/broadcaster/connect', [BroadcasterConnectionController::class, 'redirect'])
        ->name('twitch.broadcaster.connect');
    Route::get('twitch/broadcaster/callback', [BroadcasterConnectionController::class, 'callback'])
        ->name('twitch.broadcaster.callback');
});

Route::post('twitch/eventsub', EventSubController::class)->name('twitch.eventsub');
