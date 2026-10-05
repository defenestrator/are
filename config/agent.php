<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VTuber agent bridge (#10)
    |--------------------------------------------------------------------------
    |
    | /api/agent lets an Orkestera-driven VTuber read the queue, claim and
    | answer questions, change the avatar's expression and act through the
    | Chat Control Bus. Every request is refused while the kill switch is on
    | (the bus kill switch: one switch stops both), while a moderator has
    | stopped the agent on /agent, or while 'enabled' is false here.
    |
    | Issue a token with `php artisan agent:token <name>`.
    |
    */

    'enabled' => (bool) env('AGENT_ENABLED', true),

    'requests_per_minute' => (int) env('AGENT_REQUESTS_PER_MINUTE', 120),

    // An address that fails authentication this often in a minute is
    // refused (429) before its requests are logged.
    'failed_auth_per_minute' => (int) env('AGENT_FAILED_AUTH_PER_MINUTE', 30),

    // Avatar expressions per second per token, on top of the general limit.
    'expressions_per_second' => (int) env('AGENT_EXPRESSIONS_PER_SECOND', 1),

    // How long the request log is kept (pruned daily).
    'log_days' => (int) env('AGENT_LOG_DAYS', 14),

    // Agent tokens expire after this many days (agent:token --days overrides).
    'token_days' => (int) env('AGENT_TOKEN_DAYS', 30),

    // How many queue items GET /api/agent/queue returns at most.
    'queue_limit' => 50,

    /*
    | Avatar expressions: POST /api/agent/expression {"expression": "happy"}.
    | Only names listed under 'expressions' are accepted. Drivers:
    |   log           write it to the log (the default, and for testing)
    |   vtube_studio  POST a VTube Studio HotkeyTriggerRequest to 'url', a
    |                 bridge that relays it to VTube Studio's WebSocket API;
    |                 each expression maps to a hotkey id
    |   warudo        POST {"action": "expression", "name": <value>} to 'url',
    |                 for a Warudo blueprint listening for HTTP
    */
    'avatar' => [
        'driver' => env('AGENT_AVATAR_DRIVER', 'log'),
        'url' => env('AGENT_AVATAR_URL'),
        'token' => env('AGENT_AVATAR_TOKEN'),
        'timeout_seconds' => 3,
        'expressions' => [
            'neutral' => env('AGENT_EXPRESSION_NEUTRAL', 'neutral'),
            'happy' => env('AGENT_EXPRESSION_HAPPY', 'happy'),
            'thinking' => env('AGENT_EXPRESSION_THINKING', 'thinking'),
            'surprised' => env('AGENT_EXPRESSION_SURPRISED', 'surprised'),
            'sad' => env('AGENT_EXPRESSION_SAD', 'sad'),
        ],
    ],

    /*
    | The intermission scene, cut to whenever the kill switch is thrown.
    | Drivers:
    |   log       write it to the log (the default)
    |   obs_http  an obs-websocket HTTP bridge (such as obs-websocket-http):
    |             POST {url}/emit/SetCurrentProgramScene {"sceneName": scene},
    |             with 'token' as the Authorization header
    */
    'obs' => [
        'driver' => env('AGENT_OBS_DRIVER', 'log'),
        'url' => env('AGENT_OBS_URL'),
        'token' => env('AGENT_OBS_TOKEN'),
        'intermission_scene' => env('AGENT_OBS_INTERMISSION_SCENE', 'Intermission'),
        'timeout_seconds' => 2,
    ],

];
