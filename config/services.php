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

    "twitch" => [
        "client_id" => env("TWITCH_CLIENT_ID"),
        "client_secret" => env("TWITCH_CLIENT_SECRET"),
        "redirect" => env("TWITCH_REDIRECT_URL"),
        "broadcaster_id" => env("TWITCH_CHANNEL_ID"),
        // Additional channels this app serves (e.g. a second Twitch channel), comma separated.
        "broadcaster_ids" => array_filter(explode(",", env("TWITCH_BROADCASTER_IDS", ''))),
        "friend_ids" => array_filter(explode(",", env("TWITCH_FRIEND_IDS", ''))),
        "broadcaster_redirect" => env("TWITCH_BROADCASTER_REDIRECT_URL"),
        "eventsub_secret" => env("TWITCH_HELIX_EVENTSUB_SECRET"),
        "eventsub_callback" => env("TWITCH_EVENTSUB_CALLBACK_URL"),
    ],

    "facebook" => [
        "client_id" => env("FACEBOOK_CLIENT_ID"),
        "client_secret" => env("FACEBOOK_CLIENT_SECRET"),
        "redirect" => env("FACEBOOK_REDIRECT_URL"),
    ],

];
