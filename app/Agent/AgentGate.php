<?php

namespace App\Agent;

use App\Models\BusControl;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The hard gate in front of every agent action (#10). It reads the switches
 * from the database each time it is asked: nothing is cached or memoised, so
 * a kill thrown by any moderator, in any process, refuses the agent's very
 * next request, and any action already past the middleware is refused again
 * just before it has an effect.
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
