<?php

namespace App\Policies;

use App\IdentityProvider;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserBan;
use Illuminate\Auth\Access\Response;

class UserBanPolicy
{
    /**
     * Lifting one ban follows UserPolicy::unban for the banned user. A ban
     * whose user deleted their account is judged by the accounts it covers:
     * only the broadcaster may lift it if one of them is a Twitch moderator.
     */
    public function lift(User $user, UserBan $ban): Response
    {
        if ($ban->user !== null) {
            return $user->can('unban', $ban->user) ? Response::allow() : Response::deny();
        }

        if (! $user->can('moderate')) {
            return Response::deny();
        }

        $coversModerator = TwitchModerator::whereIn('broadcaster_id', User::getBroadcasterIDs())
            ->whereIn('twitch_user_id', $ban->identities()
                ->where('provider', IdentityProvider::Twitch)
                ->select('provider_user_id'))
            ->exists();

        if ($coversModerator && ! $user->isBroadcaster()) {
            return Response::deny('Only the broadcaster can lift a ban on a moderator.');
        }

        return Response::allow();
    }
}
