<?php

use App\Providers\AppServiceProvider;
use App\Providers\BusOverlayServiceProvider;
use App\Providers\ChatCommandServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    BusOverlayServiceProvider::class,
    ChatCommandServiceProvider::class,
    HorizonServiceProvider::class,
    VoltServiceProvider::class,
];
