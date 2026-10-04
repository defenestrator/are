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
 * time; the ids of actions vetoed in the last hour, so an adapter can undo
 * one it already ran; and the bus state. While the kill switch is on it
 * returns no actions at all.
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

        $after = max(0, (int) $request->query('after', '0'));
        $state = BusState::read($game);

        $actions = $state->killed ? collect() : BusPublication::where('game', $game->key)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::PAGE)
            ->get();

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
            'cursor' => $actions->last()->id ?? $after,
            'actions' => $actions->map(fn (BusPublication $p) => $p->payload())->values(),
            'vetoed' => $vetoed,
        ])->header('Cache-Control', 'no-store, private');
    }
}
