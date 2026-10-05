<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\KillSwitchController;
use Illuminate\Support\Facades\Route;

/*
| The VTuber agent bridge (#10). Every agent route is logged in full
| (agent.log runs first, so refusals are logged too), needs an agent's
| Sanctum token with the route's ability, and passes the kill-switch gate,
| which reads the switch from the database on every request.
*/
Route::prefix('agent')
    ->middleware(['agent.log', 'auth:sanctum', 'agent.only', 'throttle:agent', 'agent.gate'])
    ->name('agent.')
    ->group(function () {
        Route::get('queue', [AgentController::class, 'queue'])->middleware('abilities:agent:queue')->name('queue');
        Route::post('questions/{question}/claim', [AgentController::class, 'claim'])->middleware('abilities:agent:answer')->name('claim');
        Route::post('questions/{question}/answer', [AgentController::class, 'answer'])->middleware('abilities:agent:answer')->name('answer');
        Route::post('expression', [AgentController::class, 'expression'])->middleware('abilities:agent:avatar')->name('expression');
        Route::post('bus/actions', [AgentController::class, 'busAction'])->middleware('abilities:agent:bus')->name('bus');
    });

// The kill switch for a Stream Deck button: a moderator's token from
// `agent:kill-token`, not an agent's.
Route::post('kill-switch', KillSwitchController::class)
    ->middleware(['auth:sanctum', 'abilities:kill-switch', 'throttle:kill-switch'])
    ->name('kill-switch');
