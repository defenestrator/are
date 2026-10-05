<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'twitch' => [
        'client_id' => env('TWITCH_CLIENT_ID'),
        'client_secret' => env('TWITCH_CLIENT_SECRET'),
        'redirect' => env('TWITCH_REDIRECT_URL'),
        'broadcaster_id' => env('TWITCH_CHANNEL_ID'),
        // Additional channels this app serves (e.g. a second Twitch channel), comma separated.
        'broadcaster_ids' => array_filter(explode(',', env('TWITCH_BROADCASTER_IDS', ''))),
        'friend_ids' => array_filter(explode(',', env('TWITCH_FRIEND_IDS', ''))),
        'broadcaster_redirect' => env('TWITCH_BROADCASTER_REDIRECT_URL'),
        'eventsub_secret' => env('TWITCH_HELIX_EVENTSUB_SECRET'),
        'eventsub_callback' => env('TWITCH_EVENTSUB_CALLBACK_URL'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URL'),
    ],

    // YouTube Live Chat ingestion (#24). Read with an API key; see spike #23.
    'youtube' => [
        'api_key' => env('YOUTUBE_API_KEY'),
        // The channels whose live chat this app reads, comma separated. Videos
        // from any other channel are refused, and while this is empty nothing
        // is read or connected at all (youtube:chat, --auto, the OAuth connect).
        'channel_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('YOUTUBE_CHANNEL_IDS', ''))))),
        // Never poll faster than this, whatever pollingIntervalMillis says.
        'poll_floor_ms' => (int) env('YOUTUBE_POLL_FLOOR_MS', 3000),
        // A Google OAuth client (Web application) for channel owners to grant
        // youtube.force-ssl, so ARE can post chat replies (#110). Register the
        // redirect URI on the client.
        'oauth' => [
            'client_id' => env('YOUTUBE_OAUTH_CLIENT_ID'),
            'client_secret' => env('YOUTUBE_OAUTH_CLIENT_SECRET'),
            'redirect' => env('YOUTUBE_OAUTH_REDIRECT_URL'),
        ],
        // Automatic discovery of the live video (#127): `youtube:chat --auto`
        // runs every minute inside these weekly windows ("sun 17:00-21:00",
        // comma separated, in `timezone`) and searches each channel at most
        // every `search_every_minutes`.
        'auto' => [
            'windows' => array_values(array_filter(array_map('trim', explode(',', (string) env('YOUTUBE_SHOW_WINDOWS', ''))))),
            'timezone' => env('YOUTUBE_SHOW_TIMEZONE', 'UTC'),
            'search_every_minutes' => (int) env('YOUTUBE_AUTO_SEARCH_EVERY_MINUTES', 15),
        ],
        'quota' => [
            // Per Google Cloud project, per Pacific Time day.
            'daily_units' => (int) env('YOUTUBE_QUOTA_DAILY_UNITS', 10000),
            'daily_search_calls' => (int) env('YOUTUBE_QUOTA_DAILY_SEARCH_CALLS', 100),
            'alert_ratio' => (float) env('YOUTUBE_QUOTA_ALERT_RATIO', 0.8),
        ],
    ],

];
