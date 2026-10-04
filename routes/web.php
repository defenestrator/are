<?php

use App\Models\Question;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Component;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect('/vote');
    } else {
        return view('welcome');
    }
})->name('home');

Route::get('vote', function () {
    return view('vote', []);
})
    ->middleware(['auth', 'not-banned'])
    ->name('dashboard');

Route::middleware(['auth', 'not-banned'])->group(function () {
    // volt route for settings.profile
    Route::get('settings', function () {
        return view('livewire.settings.profile');
    })->name('settings');
});

Route::get('moderation', function () {
    return view('moderation');
})
    ->middleware(['auth', 'not-banned', 'can:moderate'])
    ->name('moderation');

Route::get('/visualizer', function () {
    return view('visualizer');
})->name('visualizer');

// Conversion (#15): the /about page with its lead form, and UTM short links.
Route::view('about', 'about')->name('about');
Route::get('go/{shortLink:code}', App\Http\Controllers\ShortLinkRedirectController::class)->name('short-links.go');

require __DIR__ . '/auth.php';
require __DIR__ . '/overlays.php';
