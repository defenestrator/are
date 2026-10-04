<?php

namespace App\Jobs;

use App\IdentityProvider;
use App\Models\User;
use App\Twitch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Retries the Twitch subscription lookups that failed at sign-in, so the
 * viewer's question limit catches up without them signing in again.
 * Idempotent: it only writes tiers Helix answered, with updateOrCreate.
 */
class RefreshTwitchSubscriptions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** One pending refresh per user is enough. */
    public int $uniqueFor = 3600;

    public function __construct(public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $identity = User::find($this->userId)?->identityFor(IdentityProvider::Twitch);

        // The user or their Twitch account is gone, or no token was kept: nothing to refresh.
        if ($identity === null || $identity->access_token === null) {
            return;
        }

        $unknown = Twitch::syncUserSubscriptions($identity->user, $identity->access_token, $identity->provider_user_id);

        if ($unknown !== []) {
            // Throwing hands the job back to the queue for the next backoff step.
            throw new RuntimeException('Twitch subscription lookup still failing for '.count($unknown).' channel(s).');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Gave up refreshing Twitch subscriptions; they refresh at the next sign-in.', [
            'user_id' => $this->userId,
            'reason' => $exception?->getMessage(),
        ]);
    }
}
