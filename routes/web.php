<?php

use App\Http\Controllers\AttributionController;
use App\Http\Controllers\BusActionsController;
use App\Http\Controllers\ClipFileController;
use App\Http\Controllers\MusicPlayerController;
use App\Http\Controllers\ShortLinkRedirectController;
use App\Http\Controllers\StreamSafePackController;
use App\Models\StreamMarker;
use App\Models\Track;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
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

// Clip markers (#11): mods and broadcasters, the seed of the approval queue.
Route::view('clips', 'clips')
    ->middleware(['auth', 'not-banned', 'can:moderate'])
    ->name('clips.index');
Route::get('clips/{marker}/{variant}.mp4', ClipFileController::class)
    ->whereNumber('marker')
    ->whereIn('variant', StreamMarker::FILES)
    ->middleware(['auth', 'not-banned', 'can:moderate'])
    ->name('clips.file');

// Chat Control Bus (#9): moderators run it at /bus; game adapters poll
// /bus/{game}/actions with a token from `bus:token` when Reverb is not there.
Route::get('bus', function () {
    return view('bus');
})
    ->middleware(['auth', 'not-banned', 'can:moderate'])
    ->name('bus');

// VTuber agent bridge (#10): what the agent took and said, its request log,
// and the kill switch. The agent's own API is in routes/api.php.
Route::get('agent', function () {
    return view('agent');
})
    ->middleware(['auth', 'not-banned', 'can:moderate'])
    ->name('agent');
Route::get('bus/{game}/actions', BusActionsController::class)
    ->where('game', '[a-z0-9_-]{1,64}')
    ->middleware('throttle:bus-adapter')
    ->name('bus.actions');

Route::get('/visualizer', function () {
    return view('visualizer');
})->name('visualizer');

// Conversion (#15): the /about page with its lead form, and UTM short links.
Route::view('about', 'about')->name('about');
// /go keeps its state in cookies, not the session, so it starts none: a
// cookieless bot or script must not write a sessions row per hit (#90).
Route::get('go/{shortLink:code}', ShortLinkRedirectController::class)
    ->middleware('throttle:short-links')
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])
    ->name('short-links.go');
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

// Launch readiness (#135): broadcasters only. Like attribution, no auth
// middleware, so a guest gets the gate's 403 rather than a login redirect.
Route::view('admin/readiness', 'admin.readiness')
    ->middleware('can:viewReadiness')
    ->name('admin.readiness');

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

// Play, skip and clear song requests from !song and channel points.
Volt::route('music/requests', 'music.requests')
    ->middleware(['auth', 'not-banned', 'can:moderate'])
    ->name('music.requests');

// A local player advances the queue when a track really starts (#136). The
// bearer token is the credential, so no session or CSRF, as for /go.
Route::post('music/requests/advance', MusicPlayerController::class)
    ->middleware('throttle:music-player')
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])
    ->name('music.requests.advance');

require __DIR__.'/auth.php';
require __DIR__.'/overlays.php';
