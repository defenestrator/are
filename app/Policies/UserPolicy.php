<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Ban or time out a user locally. A broadcaster cannot be banned here,
     * and nobody can ban themselves.
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

        return Response::allow();
    }

    /**
     * Lift a user's local bans.
     */
    public function unban(User $user, User $target): bool
    {
        return $user->can('moderate');
    }
}
