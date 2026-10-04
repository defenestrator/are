<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chat Control Bus (#9)
    |--------------------------------------------------------------------------
    |
    | Turns chat (!do ...) into game actions and publishes them to game
    | adapters on the Reverb channel bus.{game}, or by polling
    | GET /bus/{game}/actions. Moderators run it from /bus.
    |
    | 'enabled' is a deploy-time off switch. The runtime kill switch, pauses
    | and the running game are set on /bus and stored in bus_controls.
    |
    */

    'enabled' => (bool) env('BUS_ENABLED', true),

    // Seconds each platform's chat lags behind the stream. A vote window is
    // extended by the slowest platform in 'platforms', so viewers on every
    // platform get the whole window.
    'platform_latency_seconds' => [
        'twitch' => (int) env('BUS_LATENCY_TWITCH', 3),
        'youtube' => (int) env('BUS_LATENCY_YOUTUBE', 12),
        'facebook' => (int) env('BUS_LATENCY_FACEBOOK', 10),
    ],

    // Platforms whose chat feeds the bus right now, comma separated.
    'platforms' => array_values(array_filter(array_map('trim', explode(',', (string) env('BUS_PLATFORMS', 'twitch'))))),

    /*
    | Each game: a label, its default mode (democracy, anarchy or
    | weighted_random, which moderators can change live), the base window in
    | seconds, the per-person rate limit in anarchy, and its verbs.
    |
    | A verb's argument is one of:
    |   none                      !do up
    |   text (min, max)           !do task Write the README
    |   integer (min, max)        !do move 3
    |   choice (options)          !do lane left
    */
    'games' => [

        'orkestera' => [
            'label' => 'Chat Plays Orkestera',
            'mode' => env('BUS_ORKESTERA_MODE', 'democracy'),
            'window_seconds' => (int) env('BUS_ORKESTERA_WINDOW_SECONDS', 60),
            'anarchy' => ['actions' => 3, 'per_seconds' => 60],
            'verbs' => [
                'task' => ['argument' => 'text', 'min' => 5, 'max' => 200],
            ],
        ],

    ],

];
