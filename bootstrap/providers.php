<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChatCommandServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    // App\Providers\FolioServiceProvider::class,
    ChatCommandServiceProvider::class,
    HorizonServiceProvider::class,
    VoltServiceProvider::class,
];
