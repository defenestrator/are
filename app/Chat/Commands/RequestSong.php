<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommand;
use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\Enums\SongRequestSource;
use App\Exceptions\SongRequestRejected;
use App\Models\SongRequest;
use App\SongRequests;

/**
 * !song <title or number>: request a track from the stream-safe catalogue,
 * as the linked user. Only Track::requestable() tracks can be requested.
 */
class RequestSong implements ChatCommand
{
    public function names(): array
    {
        return ['song'];
    }

    public function requiresUser(): bool
    {
        return true;
    }

    public function handle(ChatCommandInvocation $invocation): ChatCommandResult
    {
        if ($invocation->arguments === '') {
            return ChatCommandResult::rejected('Usage: !song <title or number>. Songs: '.route('music.index'));
        }

        try {
            $track = SongRequests::resolve($invocation->arguments);
            $request = SongRequests::request($track, $invocation->user, $invocation->chatterName, SongRequestSource::Chat);
        } catch (SongRequestRejected $e) {
            return ChatCommandResult::rejected($e->getMessage());
        }

        $position = SongRequest::queued()->where('id', '<=', $request->id)->count();

        // Song numbers, not titles: chat replies carry only ARE ids (#128).
        return ChatCommandResult::done("Requested song #{$track->id}. It is number {$position} in the queue.");
    }
}
