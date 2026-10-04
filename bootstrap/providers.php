<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    // App\Providers\FolioServiceProvider::class,
    HorizonServiceProvider::class,
    VoltServiceProvider::class,
];
