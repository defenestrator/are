<?php

use App\Http\Controllers\AgentClipController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\KillSwitchController;
use Illuminate\Support\Facades\Route;

/*
| The VTuber agent bridge (#10). An address that keeps failing auth is
| refused before anything is logged (agent.ip). Every other request is
| logged (agent.log wraps auth, so refusals are logged too), needs an
| agent's Sanctum token with the route's ability, and passes the
| kill-switch gate, which reads the switch from the database every time.
*/
Route::prefix('agent')
    ->middleware(['agent.ip', 'agent.log', 'auth:sanctum', 'agent.only', 'throttle:agent', 'agent.gate'])
    ->name('agent.')
    ->group(function () {
        Route::get('clips', [AgentClipController::class, 'index'])->middleware('abilities:agent:clips:read')->name('clips.index');
        Route::get('clips/{marker}', [AgentClipController::class, 'show'])->middleware('abilities:agent:clips:read')->name('clips.show');
        Route::get('clips/{marker}/files/{variant}', [AgentClipController::class, 'file'])->whereIn('variant', ['landscape', 'portrait', 'short'])->middleware('abilities:agent:clips:read')->name('clips.file');
        Route::post('clips/{marker}/render', [AgentClipController::class, 'render'])->middleware('abilities:agent:clips:render')->name('clips.render');
        Route::post('clips/{marker}/releases', [AgentClipController::class, 'release'])->middleware('abilities:agent:clips:release')->name('clips.release');
        Route::get('clips/{marker}/releases/{release}', [AgentClipController::class, 'releaseStatus'])->middleware('abilities:agent:clips:read')->name('clips.release-status');
        Route::get('queue', [AgentController::class, 'queue'])->middleware('abilities:agent:queue')->name('queue');
        Route::post('questions/{question}/claim', [AgentController::class, 'claim'])->middleware('abilities:agent:answer')->name('claim');
        Route::post('questions/{question}/answer', [AgentController::class, 'answer'])->middleware('abilities:agent:answer')->name('answer');
        Route::post('expression', [AgentController::class, 'expression'])->middleware(['abilities:agent:avatar', 'throttle:agent-expression'])->name('expression');
        Route::post('bus/actions', [AgentController::class, 'busAction'])->middleware('abilities:agent:bus')->name('bus');
    });

// The kill switch for a Stream Deck button: a moderator's token from
// `agent:kill-token`, not an agent's.
Route::post('kill-switch', KillSwitchController::class)
    ->middleware(['auth:sanctum', 'abilities:kill-switch', 'throttle:kill-switch'])
    ->name('kill-switch');
