<?php

namespace App;

enum TwitchSubscription: string
{
    case None = "0000";
    case Tier1 = "1000";
    case Tier2 = "2000";
    case Tier3 = "3000";

    public function isSubscribed(): bool
    {
        return $this->value !== "0000";
    }
}

