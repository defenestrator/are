<?php

use Illuminate\Support\Facades\Route;

Route::get(uri: '/', action: function (){
    return view(view: 'home');
});

require __DIR__.'/auth.php';
