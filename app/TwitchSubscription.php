<?php

namespace App;

// GOLANG IN ABSOLUTE SHAMBLES!!!!
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

    public function maxActiveQuestions(): int
    {
        return match ($this) {
            self::Tier1 => 6,
            self::Tier2 => 72,
            self::Tier3 => 144,
            default =>6,
        };
    }
}
