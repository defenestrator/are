<?php

namespace App\Http\Controllers;

use App\Models\MusicPlayerToken;
use App\Models\SongRequest;
use App\SongRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /music/requests/advance (#136): lets a local player (an OBS script, a
 * Mac Shortcut, curl) move the song request queue when a track really starts,
 * so the now-playing overlay follows the audio.
 *
 * The credential is `Authorization: Bearer <token>` from
 * `php artisan music:player-token`, never the URL, so it stays out of access
 * logs. There is no session and no CSRF check, as for /go (#90): the bearer
 * token is the credential. Rate-limited per address ("music-player"), and
 * every advance is in the moderation audit log under the player's name.
 *
 * Body (form or JSON): action=next (default) finishes the request on air and
 * starts the oldest queued one; action=done only finishes the one on air.
 */
class MusicPlayerController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $player = MusicPlayerToken::findByToken($request->bearerToken());

        if ($player === null) {
            // Never log the token that was sent.
            Log::warning('Rejected a music player request with a missing or unknown token.', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        // Answered as JSON whatever the client sends: this route has no session
        // to redirect validation errors through.
        $action = $request->input('action', 'next');
        if (! in_array($action, ['next', 'done'], true)) {
            return response()->json(['message' => 'The action must be "next" or "done".'], 422);
        }

        if ($action === 'done') {
            SongRequests::finishForPlayer($player);
            $playing = null;
        } else {
            $playing = SongRequests::advanceForPlayer($player);
        }

        $player->forceFill(['last_used_at' => now()])->save();

        return response()->json([
            'action' => $action,
            'now_playing' => $playing === null ? null : $this->describe($playing->load('track')),
            'queued' => SongRequest::queued()->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(SongRequest $request): array
    {
        return [
            'id' => $request->id,
            'title' => $request->track->title,
            'artist' => $request->track->artist,
            'attribution' => $request->track->creditLine(),
            'requested_by' => $request->requester_name,
            'started_at' => $request->started_at?->toIso8601String(),
        ];
    }
}
