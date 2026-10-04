<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Ban or time out a user locally. A broadcaster cannot be banned here,
     * nobody can ban themselves, and only the broadcaster can ban a moderator.
     */
    public function ban(User $user, User $target): Response
    {
        if (! $user->can('moderate')) {
            return Response::deny();
        }
        if ($target->is($user)) {
            return Response::deny('You cannot ban yourself.');
        }
        if ($target->isBroadcaster()) {
            return Response::deny('A broadcaster cannot be banned here.');
        }
        // As on Twitch, moderators cannot ban each other. Otherwise one rogue
        // mod could ban the rest, who (being banned) could not lift it.
        if ($target->isModerator() && ! $user->isBroadcaster()) {
            return Response::deny('Only the broadcaster can ban a moderator.');
        }

        return Response::allow();
    }

    /**
     * Lift a user's local bans. Mirrors ban(): only the broadcaster can lift a
     * ban on a moderator, or another mod could undo it and restore a rogue.
     */
    public function unban(User $user, User $target): Response
    {
        if (! $user->can('moderate')) {
            return Response::deny();
        }
        if ($target->isModerator() && ! $user->isBroadcaster()) {
            return Response::deny('Only the broadcaster can lift a ban on a moderator.');
        }

        return Response::allow();
    }
}
