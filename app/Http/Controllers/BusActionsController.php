<?php

namespace App\Http\Controllers;

use App\ControlBus\BusState;
use App\ControlBus\Game;
use App\Models\BusAdapterToken;
use App\Models\BusPublication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /bus/{game}/actions?after={id}: the polling fallback for game adapters
 * that cannot hold a Reverb socket (and for all of them until production has
 * Reverb). Authenticated with the game's bearer token from `bus:token`.
 *
 * Returns actions published after the cursor, oldest first, at most 50 at a
 * time, leaving out vetoed ones (including everything the kill switch
 * voided); the ids of actions vetoed in the last hour, so an adapter can undo
 * one it already ran; and the bus state. While the kill switch is on it
 * returns no actions at all. Returned actions are marked delivered.
 *
 * Replay is bounded, so an adapter that restarts or was offline cannot run
 * old actions:
 * - with no ?after the cursor starts at the newest action: from now;
 * - a cursor older than bus.max_replay_seconds is clamped to that age
 *   (the response says "clamped": true);
 * - nothing at or below the game's replay floor, set by the last kill, is
 *   ever served again.
 */
class BusActionsController extends Controller
{
    public const PAGE = 50;

    public function __invoke(Request $request, string $game): JsonResponse
    {
        $game = Game::find($game);
        abort_if($game === null, 404);

        if (! BusAdapterToken::verify($game->key, $request->bearerToken())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $state = BusState::read($game);
        $newest = (int) BusPublication::where('game', $game->key)->max('id');

        $requested = $request->query('after');
        $after = $requested === null ? $newest : max(0, (int) $requested);

        $tooOld = (int) BusPublication::where('game', $game->key)
            ->where('created_at', '<', now()->subSeconds(max(0, (int) config('bus.max_replay_seconds'))))
            ->max('id');
        $clamped = $after < $tooOld;
        $from = max($after, $tooOld, $state->replayFloor);

        $page = $state->killed ? collect() : BusPublication::where('game', $game->key)
            ->where('id', '>', $from)
            ->orderBy('id')
            ->limit(self::PAGE)
            ->get();

        // The cursor moves past vetoed actions too, so they are never retried.
        $cursor = $page->last()->id ?? $from;
        $actions = $page->whereNull('vetoed_at')->values();

        if ($actions->isNotEmpty()) {
            BusPublication::whereKey($actions->pluck('id'))->whereNull('delivered_at')->update(['delivered_at' => now()]);
        }

        $vetoed = BusPublication::where('game', $game->key)
            ->where('vetoed_at', '>=', now()->subHour())
            ->orderBy('id')
            ->pluck('id');

        return response()->json([
            'game' => $game->key,
            'running' => $state->activeGame === $game->key,
            'killed' => $state->killed,
            'paused' => $state->paused,
            'mode' => $state->mode->value,
            'cursor' => $cursor,
            'clamped' => $clamped,
            'actions' => $actions->map(fn (BusPublication $p) => $p->payload())->values(),
            'vetoed' => $vetoed,
        ])->header('Cache-Control', 'no-store, private');
    }
}
