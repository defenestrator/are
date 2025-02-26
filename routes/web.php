<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect('/vote');
    } else {
        return view('welcome');
    }
})->name('home');

Route::get('explode', function() {
    throw new Exception('Boom!');
})
    ->middleware(['auth'])
    ->name('boomboom');


Route::get('vote', function() {
    return view('vote', []);
})
    ->middleware(['auth'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');
});

require __DIR__.'/auth.php';
