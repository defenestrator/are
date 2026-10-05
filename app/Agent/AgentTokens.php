<?php

namespace App\Agent;

use App\Models\Agent;
use App\Readiness\Check;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Agent tokens expire (agent:token --days, default agent.token_days), so a
 * leaked one stops working on its own. The readiness page warns before a
 * running agent's token runs out.
 */
class AgentTokens
{
    /** Warn this many days before the last token of an agent expires. */
    public const WARN_DAYS = 3;

    /**
     * When the agent's latest valid token expires, null if it has none, or
     * false if one never expires (issued before tokens had an expiry).
     */
    public static function validUntil(Agent $agent): Carbon|false|null
    {
        $tokens = $agent->tokens()->get()
            ->filter(fn (PersonalAccessToken $t) => $t->expires_at === null || $t->expires_at->isFuture());

        if ($tokens->contains(fn (PersonalAccessToken $t) => $t->expires_at === null)) {
            return false;
        }

        return $tokens->max('expires_at');
    }

    public static function readinessCheck(): Check
    {
        $name = 'Agent tokens';
        $agents = Agent::with('tokens')->orderBy('name')->get();

        if ($agents->isEmpty()) {
            return Check::skip($name, 'No VTuber agent has a token. Issue one with php artisan agent:token <name> when the agent goes live.');
        }

        $lines = [];
        $problems = [];
        foreach ($agents as $agent) {
            $until = self::validUntil($agent);

            if ($until === false) {
                $problems[] = "{$agent->name} has a token that never expires.";
                $lines[] = "{$agent->name}: a token with no expiry";
            } elseif ($until === null) {
                $problems[] = "{$agent->name} has no valid token.";
                $lines[] = "{$agent->name}: no valid token";
            } else {
                if ($until->lt(now()->addDays(self::WARN_DAYS))) {
                    $problems[] = "{$agent->name}'s token expires {$until->diffForHumans()}.";
                }
                $lines[] = "{$agent->name}: valid until {$until->toDateTimeString()}";
            }
        }

        if ($problems === []) {
            return Check::ok($name, count($agents).' agent(s) with a valid, expiring token.', $lines);
        }

        return Check::warn($name, implode(' ', $problems), 'Run php artisan agent:token <name> --rotate (add --days to choose how long it lasts), and give the agent the new token.', $lines);
    }
}
