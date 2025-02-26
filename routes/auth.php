<?php

use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;

Route::middleware('guest')->group(function () {
    Route::get("login", function() {
        return Socialite::driver("twitch")->redirect();
    });

    Route::get("twitch/auth", function() {
        $user = Socialite::driver("twitch")->user();
        dd($user->name);
    });
});

Route::post('logout', App\Livewire\Actions\Logout::class)
    ->name('logout');
