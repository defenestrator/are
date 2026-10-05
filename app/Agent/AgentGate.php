<?php

namespace App\Agent;

use App\Models\BusControl;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The hard gate in front of every agent action (#10). It reads the switches
 * from the database each time it is asked: nothing is cached or memoised, so
 * a kill thrown by any moderator, in any process, refuses the agent's very
 * next request.
 *
 * Each effect then runs inside whileAllowed(), which holds the switch rows
 * FOR SHARE and checks again under that lock. The kill switch takes the
 * global row FOR UPDATE (as the Chat Control Bus does) and stopping the
 * agent takes its row FOR UPDATE, so neither can commit between the check
 * and the effect: a kill thrown mid-request either lands first, and the
 * effect is refused, or waits for the effect to finish.
 *
 * - killed: the Chat Control Bus kill switch (one switch stops the bus and
 *   the agent, and cuts to the intermission scene);
 * - paused: a moderator stopped only the agent, on /agent;
 * - disabled: AGENT_ENABLED=false, a deploy-time off switch.
 */
class AgentGate
{
    public const SCOPE = 'agent';

    /**
     * Why the agent may not act right now, or null if it may.
     */
    public static function refusal(): ?string
    {
        if (! config('agent.enabled')) {
            return 'disabled';
        }

        $rows = BusControl::query()
            ->whereIn('scope', [BusControl::GLOBAL, self::SCOPE])
            ->get(['scope', 'killed_at', 'paused_at'])
            ->keyBy('scope');

        if (! config('bus.enabled') || $rows->get(BusControl::GLOBAL)?->killed_at !== null) {
            return 'killed';
        }

        if ($rows->get(self::SCOPE)?->paused_at !== null) {
            return 'paused';
        }

        return null;
    }

    /**
     * Refuse with 423 Locked unless the agent may act. Call right before
     * every effect, as well as in the middleware.
     */
    public static function ensure(): void
    {
        $refusal = self::refusal();

        if ($refusal !== null) {
            throw new HttpException(423, self::message($refusal), null, ['X-Agent-Refused' => $refusal]);
        }
    }

    /**
     * Run $effect only while the agent may act, holding the switch rows FOR
     * SHARE until it commits. Database effects are checked again afterwards,
     * inside the same transaction, so anything that turned the switch on
     * during the effect rolls it back. Pass $external for an effect outside
     * the database (an HTTP call), which cannot be rolled back: it is checked
     * only before, under the lock, and the kill waits for it (its timeout is
     * a few seconds).
     *
     * @template T
     *
     * @param  callable(): T  $effect
     * @return T
     */
    public static function whileAllowed(callable $effect, bool $external = false): mixed
    {
        BusControl::for(BusControl::GLOBAL);
        BusControl::for(self::SCOPE);

        return DB::transaction(function () use ($effect, $external) {
            BusControl::whereIn('scope', [BusControl::GLOBAL, self::SCOPE])->orderBy('scope')->sharedLock()->get();
            self::ensure();

            $result = $effect();

            if (! $external) {
                self::ensure();
            }

            return $result;
        });
    }

    public static function message(string $refusal): string
    {
        return match ($refusal) {
            'killed' => 'The kill switch is on. The agent may not act.',
            'paused' => 'A moderator has stopped the agent.',
            'disabled' => 'The agent bridge is disabled.',
            default => 'The agent may not act.',
        };
    }
}
