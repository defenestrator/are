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

    /*
    |--------------------------------------------------------------------------
    | Largest upload, in kilobytes
    |--------------------------------------------------------------------------
    |
    | Applies to each track file and stems zip. The same env var sets
    | Livewire's temporary upload rule (config/livewire.php), so the two
    | limits cannot drift. PHP's upload_max_filesize and post_max_size and
    | nginx's client_max_body_size must be at least this large as well.
    |
    */

    'max_upload_kb' => (int) env('MUSIC_MAX_UPLOAD_KB', 204800),

];
