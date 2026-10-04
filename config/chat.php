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

];
