<?php

use App\Http\Controllers\Testing\LoginAsController;
use Illuminate\Support\Facades\Route;

/*
| Test-only routes for the browser end-to-end suite (#155, e2e/).
|
| bootstrap/app.php loads this file only when APP_ENV=testing, and every
| controller here also refuses outside testing, so the routes cannot exist
| in production even if this file were loaded by mistake.
*/

Route::get('_e2e/login/{user}', LoginAsController::class)
    ->whereNumber('user')
    ->name('e2e.login');
