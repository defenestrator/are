<?php

use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Twitch\BroadcasterConnectionController;
use App\Http\Controllers\Twitch\EventSubController;
use App\Http\Controllers\YouTube\ChannelConnectionController as YouTubeChannelConnectionController;
use App\IdentityProvider;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [SocialiteController::class, 'redirect'])
        ->defaults('provider', IdentityProvider::Twitch->value)
        ->name('login');
});

// These are the callback URLs registered with each provider. They finish both
// signing in and linking from Settings, so they cannot require a guest.
Route::get('twitch/auth', [SocialiteController::class, 'callback'])
    ->defaults('provider', IdentityProvider::Twitch->value)
    ->name('auth.twitch.callback');

Route::get('settings/linked-accounts/{provider}/link', [SocialiteController::class, 'link'])
    ->middleware(['auth', 'not-banned'])
    ->name('identities.link');

Route::post('logout', Logout::class)
    ->name('logout');

// A broadcaster grants this app moderation and channel scopes, so it can sync
// bans and moderators and set the stream title. Register the callback URL in
// the Twitch developer console alongside the login callback.
Route::middleware('auth')->group(function () {
    Route::get('twitch/broadcaster/connect', [BroadcasterConnectionController::class, 'redirect'])
        ->name('twitch.broadcaster.connect');
    Route::get('twitch/broadcaster/callback', [BroadcasterConnectionController::class, 'callback'])
        ->name('twitch.broadcaster.callback');

    // A YouTube channel owner grants youtube.force-ssl so ARE can post chat
    // replies (#110). Register the callback URL on the Google OAuth client.
    Route::get('youtube/broadcaster/connect', [YouTubeChannelConnectionController::class, 'redirect'])
        ->name('youtube.broadcaster.connect');
    Route::get('youtube/broadcaster/callback', [YouTubeChannelConnectionController::class, 'callback'])
        ->name('youtube.broadcaster.callback');
});

Route::post('twitch/eventsub', EventSubController::class)->name('twitch.eventsub');
