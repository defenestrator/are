<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clip pipeline, slice 1: markers and clips during the stream (#11)
    |--------------------------------------------------------------------------
    |
    | per_channel_per_minute: how many !clip markers one channel may get per
    | minute, across all its mods. Each also costs Helix calls on the
    | broadcaster's token.
    |
    | create_delay_seconds: how long after the marker the clip job starts. The
    | clip ends 15 s after the marker (vod_offset = position + 15), so the VOD
    | must have recorded that far. Keep it short: Create Clip From VOD needs
    | the stream to still be live (spike #25).
    |
    | download_url_ttl_seconds: Twitch documents Get Clips Download URLs only as
    | "temporary", with no lifetime. If a URL carries a signed expiry we store
    | that; otherwise we assume this many seconds from when we fetched it.
    |
    */

    'per_channel_per_minute' => (int) env('CLIPS_PER_CHANNEL_PER_MINUTE', 5),

    'create_delay_seconds' => (int) env('CLIPS_CREATE_DELAY_SECONDS', 30),

    'download_url_ttl_seconds' => (int) env('CLIPS_DOWNLOAD_URL_TTL_SECONDS', 1800),

];
