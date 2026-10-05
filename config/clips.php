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

    /*
    |--------------------------------------------------------------------------
    | Slice 2: fetching clip files (#11)
    |--------------------------------------------------------------------------
    |
    | disk: where FetchClipFile stores the MP4s. It must be private: files
    | reach mods only through the moderate-gated clips.file route, and their
    | paths are never shown. "local" is storage/app/private.
    |
    | max_file_bytes: refuse anything larger. A 60 s 1080p60 clip is tens of MB.
    |
    | download_hosts: the only hosts a clip file is fetched from, so a bad URL
    | from upstream cannot point the server at anything else. Matched exactly
    | or as a parent domain.
    |
    */

    'disk' => env('CLIPS_DISK', 'local'),

    'max_file_bytes' => (int) env('CLIPS_MAX_FILE_BYTES', 300 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Retention (#143)
    |--------------------------------------------------------------------------
    |
    | clips:prune-files runs daily and deletes the files (never the rows) of
    | rejected clips keep_rejected_days after the decision, and of approved
    | clips not yet published keep_approved_days after approval. Clips still
    | to review are never touched.
    |
    | The readiness page turns amber when stored clip files pass
    | disk_warn_bytes, or when a local CLIPS_DISK has less than
    | disk_min_free_bytes free.
    |
    */

    'keep_rejected_days' => (int) env('CLIPS_KEEP_REJECTED_DAYS', 7),

    'keep_approved_days' => (int) env('CLIPS_KEEP_APPROVED_DAYS', 30),

    'disk_warn_bytes' => (int) env('CLIPS_DISK_WARN_BYTES', 10 * 1024 ** 3),

    'disk_min_free_bytes' => (int) env('CLIPS_DISK_MIN_FREE_BYTES', 5 * 1024 ** 3),

    'download_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CLIPS_DOWNLOAD_HOSTS', 'twitchcdn.net,twitch.tv,jtvnw.net'))))),

];
