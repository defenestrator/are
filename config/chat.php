<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chat commands (#21)
    |--------------------------------------------------------------------------
    |
    | How many chat commands (!q, !vote, ...) one person may run per minute,
    | counted across every command and platform. Commands beyond this are
    | ignored until the minute passes.
    |
    */

    'commands_per_minute' => (int) env('CHAT_COMMANDS_PER_MINUTE', 10),

    /*
    |--------------------------------------------------------------------------
    | Chat replies (#89)
    |--------------------------------------------------------------------------
    |
    | Command replies are posted back to chat by the queued PostChatReply job.
    | Each channel gets at most `per_channel` replies per `window_seconds`;
    | Twitch allows a broadcaster 100 messages per 30 seconds, and this stays
    | well under it so commands cannot flood chat. Replies older than
    | `max_age_seconds` when the job runs are dropped, because a late answer
    | in a fast chat is noise.
    |
    | YouTube is off by default: each liveChatMessages.insert costs 50 of the
    | daily quota units (spike #23).
    |
    */

    'replies' => [
        'enabled' => (bool) env('CHAT_REPLIES_ENABLED', true),
        'per_channel' => (int) env('CHAT_REPLIES_PER_CHANNEL', 20),
        'window_seconds' => (int) env('CHAT_REPLIES_WINDOW_SECONDS', 30),
        'max_age_seconds' => (int) env('CHAT_REPLIES_MAX_AGE_SECONDS', 30),
        'youtube' => (bool) env('CHAT_REPLIES_YOUTUBE', false),
    ],

];
