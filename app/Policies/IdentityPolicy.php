<?php

namespace App\Policies;

use App\Models\Identity;
use App\Models\User;

class IdentityPolicy
{
    /**
     * People unlink their own accounts. Whether an unlink is allowed at all
     * (last identity, banned) is App\Identities::unlink's call.
     */
    public function delete(User $user, Identity $identity): bool
    {
        return $user->id === $identity->user_id;
    }
}
