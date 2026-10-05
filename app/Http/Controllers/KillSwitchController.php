<?php

namespace App\Http\Controllers;

use App\ControlBus\ControlBus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/kill-switch: the kill switch as an endpoint, for a Stream Deck
 * button or a script (#10). Needs a moderator's token from
 * `agent:kill-token`, with the kill-switch ability. It is the same switch as
 * on /bus and /agent: it stops the Chat Control Bus and the agent, and cuts
 * to the intermission scene. It cannot reset the switch; a broadcaster does
 * that on /bus.
 */
class KillSwitchController extends Controller
{
    public function __invoke(Request $request, ControlBus $bus): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || Gate::forUser($user)->denies('moderate')) {
            return response()->json(['message' => 'Only a moderator\'s token can throw the kill switch.'], 403);
        }

        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $bus->kill($user, $data['reason'] ?? 'kill-switch API');

        return response()->json(['killed' => true]);
    }
}
