<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Music catalogue disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk that holds track files and stems. Files are only
    | ever served through StreamSafePackController, never by URL, so this
    | should be a private disk.
    |
    */

    'disk' => env('MUSIC_DISK', 'local'),

];
