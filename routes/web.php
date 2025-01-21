<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['equity' => "The investment we made in ourselves, version " . app()->version()];
});

require __DIR__.'/auth.php';
