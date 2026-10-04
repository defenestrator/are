<?php

namespace App\Enums;

/**
 * Where a track stands with YouTube Content ID through the distributor.
 */
enum ContentIdStatus: string
{
    case NotRegistered = 'not_registered';

    // Registered with Content ID, so anyone who uses it, us included, can be claimed.
    case Registered = 'registered';

    // Registered, but every channel we stream on is allow-listed with the distributor.
    case AllowListed = 'allow_listed';

    public function label(): string
    {
        return match ($this) {
            self::NotRegistered => 'Not registered',
            self::Registered => 'Registered with Content ID',
            self::AllowListed => 'Registered, channels allow-listed',
        };
    }

    /**
     * A registered track that is not allow-listed gets streams claimed, so
     * it can never be stream-safe.
     */
    public function permitsStreamSafe(): bool
    {
        return $this !== self::Registered;
    }
}
