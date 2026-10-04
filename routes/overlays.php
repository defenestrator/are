<?php

use App\Enums\Overlay;
use App\Http\Controllers\OverlayController;
use App\Http\Controllers\OverlaySessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| OBS browser sources: /overlay/{queue,vote,now-playing,captions,visualizer,top-vote,cta}
| with ?layout=horizontal|vertical and #token=… in the fragment. Issue tokens
| with `php artisan overlay:token`. The page trades the fragment token for a
| grant cookie at /overlay/{overlay}/session; see App\Support\OverlayGrant.
*/

Route::get('overlay/{overlay}', OverlayController::class)
    ->whereIn('overlay', Overlay::values())
    ->middleware('overlay.token')
    ->name('overlay.show');

Route::post('overlay/{overlay}/session', OverlaySessionController::class)
    ->whereIn('overlay', Overlay::values())
    ->middleware('throttle:overlay-session')
    ->name('overlay.session');

// The top-vote overlay used to live here. Route::permanentRedirect drops the
// query string, which may still carry a legacy ?token=, so redirect by hand.
// Browsers keep a #token= fragment across the redirect on their own.
Route::get('top-vote', fn (Request $request) => redirect()->route(
    'overlay.show',
    [...$request->query(), 'overlay' => Overlay::TopVote],
    301,
))->name('top-vote');
