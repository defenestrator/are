<?php

use App\Http\Controllers\AttributionController;
use App\Http\Controllers\ShortLinkRedirectController;
use App\Http\Controllers\StreamSafePackController;
use App\Models\Track;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

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
Route::get('go/{shortLink:code}', ShortLinkRedirectController::class)->name('short-links.go');
// Leads list (#31): broadcasters only, through LeadPolicy.
Route::view('leads', 'leads')
    ->middleware(['auth', 'not-banned', 'can:viewAny,App\Models\Lead'])
    ->name('leads.index');

// Lead attribution (#12): viewAttribution is LeadPolicy::viewAny. No auth
// middleware, so a guest gets the gate's 403, as on Horizon, rather than a
// login redirect.
Route::middleware('can:viewAttribution')->prefix('admin')->group(function () {
    Route::get('attribution', [AttributionController::class, 'index'])->name('admin.attribution');
    Route::get('attribution.csv', [AttributionController::class, 'export'])->name('admin.attribution.export');
});

// The public stream-safe pack: original music other creators may use on stream.
Route::controller(StreamSafePackController::class)->prefix('music')->name('music.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('{track}/download', 'download')->where('track', '[0-9]{1,18}')->name('download');
        Route::get('{track}/stems', 'stems')->where('track', '[0-9]{1,18}')->name('stems');
    });
});

Volt::route('music/catalogue', 'music.catalogue')
    ->middleware(['auth', 'not-banned', 'can:viewAny,'.Track::class])
    ->name('music.catalogue');

require __DIR__.'/auth.php';
require __DIR__.'/overlays.php';
