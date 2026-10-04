<?php

use Illuminate\Support\Facades\Artisan;

// Removed in #47: it was killed after 60 s, never deleted old segments and
// wrote microphone audio into public/. Nothing in the show consumed it.
test('the twitch:transcode-stream command is gone', function () {
    expect(Artisan::all())->not->toHaveKey('twitch:transcode-stream');
});
