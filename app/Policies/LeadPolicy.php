<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Leads are business PII, so only broadcasters may read them. Moderators,
 * who can do everything else in ARE, cannot.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isBroadcaster() && ! $user->isBanned();
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->viewAny($user);
    }
}
