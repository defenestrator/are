<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;

Route::middleware('guest')->group(function () {
    Route::get("login", function() {
        return Socialite::driver("twitch")->redirect();
    });

    Route::get("twitch/auth", function() {
        $twichUser = Socialite::driver("twitch")->user();
        $existingUser = User::where("twitch_id", $twichUser->id)->first();

        if ($existingUser) {
            Auth::login($existingUser);
            $existingUser->twitch_access_token = $twichUser->token;
            $existingUser->twitch_refresh_token = $twichUser->refreshToken;
            $existingUser->twitch_expires_in = $twichUser->expiresIn;
            $existingUser->save();
            return redirect('/vote');
        }

        $user = new User;

        $user->name = $twichUser->name;
        $user->email = $twichUser->email;
        $user->twitch_id = $twichUser->id;
        $user->twitch_access_token = $twichUser->token;
        $user->twitch_refresh_token = $twichUser->refreshToken;
        $user->twitch_expires_in = $twichUser->expiresIn;
        $user->twitch_avatar_url = $twichUser->avatar;

        $user->save();

        Auth::login($user);

        return redirect('/vote');
    });
});

Route::post('logout', App\Livewire\Actions\Logout::class)
    ->name('logout');
