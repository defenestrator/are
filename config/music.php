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

    /*
    |--------------------------------------------------------------------------
    | Song requests
    |--------------------------------------------------------------------------
    |
    | requests_per_user: how many songs one person may have waiting in the
    | request queue at once, through !song. Channel-point requests are paid
    | for with points, so they do not count against it.
    |
    | song_request_reward_id: the Twitch custom reward whose redemptions
    | become song requests, using the redeemer's text as the song. Rewards
    | belong to one channel, so list one id per channel, comma-separated.
    | Create it with `php artisan music:create-song-reward`, so that refused
    | requests can be refunded: Twitch only lets the client id that created
    | a reward update its redemptions. Leave it empty to turn channel-point
    | requests off.
    |
    */

    'requests_per_user' => (int) env('MUSIC_REQUESTS_PER_USER', 2),

    'song_request_reward_id' => env('MUSIC_SONG_REQUEST_REWARD_ID'),

];
