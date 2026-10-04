<?php

namespace App\Jobs;

use App\Exceptions\TwitchTokenRejected;
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

        // Viewer tokens last about four hours, less than this job's backoff.
        // A rejected refresh is logged and clears the tokens: give up quietly,
        // and the next sign-in refreshes the tiers. A transient failure throws,
        // which retries the job.
        if ($identity->tokenExpired() && ! Twitch::refreshUserToken($identity)) {
            return;
        }

        try {
            $unknown = Twitch::syncUserSubscriptions($identity->user, $identity->access_token, $identity->provider_user_id);
        } catch (TwitchTokenRejected) {
            // Revoked or expired ahead of its stored expiry: refresh once, retry once.
            if (! Twitch::refreshUserToken($identity)) {
                return;
            }

            try {
                $unknown = Twitch::syncUserSubscriptions($identity->user, $identity->access_token, $identity->provider_user_id);
            } catch (TwitchTokenRejected) {
                // A fresh token rejected too is not something a later attempt
                // fixes (a missing scope, say), so stop rather than burn retries.
                Log::warning('Helix rejected the viewer token even after a refresh; giving up until they sign in again.', [
                    'user_id' => $this->userId,
                    'twitch_user_id' => $identity->provider_user_id,
                ]);

                return;
            }
        }

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
