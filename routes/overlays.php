<?php

use App\Enums\Overlay;
use App\Http\Controllers\OverlayController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| OBS browser sources: /overlay/{queue,vote,now-playing,captions,visualizer,top-vote,cta}
| with ?layout=horizontal|vertical and ?token=. Issue tokens with `php artisan overlay:token`.
*/

Route::get('overlay/{overlay}', OverlayController::class)
    ->whereIn('overlay', Overlay::values())
    ->middleware('overlay.token')
    ->name('overlay.show');

// The top-vote overlay used to live here. Route::permanentRedirect drops the
// query string, and the query carries the token, so redirect by hand.
Route::get('top-vote', fn (Request $request) => redirect()->route(
    'overlay.show',
    [...$request->query(), 'overlay' => Overlay::TopVote],
    301,
))->name('top-vote');
