<?php

namespace App\Policies;

use App\Models\Track;
use App\Models\User;

/**
 * The catalogue is managed by ARE's admins: the broadcaster and the channel's
 * moderators (User::isAdminUser()), through the ban-aware `moderate` gate.
 * Downloads are public and are not gated here; StreamSafePackController only
 * resolves stream-safe tracks, so anything else is a 404.
 */
class TrackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('moderate');
    }

    public function create(User $user): bool
    {
        return $user->can('moderate');
    }

    public function update(User $user, Track $track): bool
    {
        return $user->can('moderate');
    }

    public function delete(User $user, Track $track): bool
    {
        return $user->can('moderate');
    }
}
