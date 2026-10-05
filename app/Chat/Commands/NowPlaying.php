<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\Models\SongRequest;
use App\SongRequests;

/**
 * !np: anyone may ask what is playing. The channel's broadcaster and
 * moderators may also move the song request queue from chat (#136):
 * `!np next` finishes the request on air and starts the next one, and
 * `!np done` only finishes the one on air.
 */
class NowPlaying implements ChatCommand
{
    public function names(): array
    {
        return ['np'];
    }

    public function requiresUser(): bool
    {
        return false;
    }

    public function handle(ChatCommandInvocation $invocation): ChatCommandResult
    {
        $action = strtolower($invocation->arguments);

        if ($action === '') {
            return ChatCommandResult::done(self::describe(SongRequests::nowPlaying()));
        }

        if (! in_array($action, ['next', 'done'], true)) {
            return ChatCommandResult::rejected('Usage: !np, or for moderators !np next | !np done');
        }

        // canModerate() is scoped to the channel the message arrived on (#96).
        if ($invocation->user === null || ! $invocation->canModerate()) {
            return ChatCommandResult::rejected("Only moderators of this channel can use !np {$action}.");
        }

        if ($action === 'done') {
            SongRequests::finish($invocation->user);

            return ChatCommandResult::done('Marked the current song as played.');
        }

        return ChatCommandResult::done(self::describe(SongRequests::advance($invocation->user)));
    }

    /**
     * A fixed template and the song number, never the title or the requester's
     * name: chat replies post as the channel and carry only ARE ids (#128).
     * The title is on the overlay and in the pack.
     */
    private static function describe(?SongRequest $request): string
    {
        if ($request === null) {
            return 'Nothing is playing.';
        }

        return "Now playing song #{$request->track_id}. Songs: ".route('music.index');
    }
}
