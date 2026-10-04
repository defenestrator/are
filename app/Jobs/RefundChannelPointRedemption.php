<?php

namespace App\Jobs;

use App\Models\BroadcasterToken;
use App\Models\ChannelPointRedemption;
use App\Twitch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Refunds a channel-point redemption by cancelling it through Helix (#124).
 * QueueSongFromRedemption dispatches it when it refuses a song request.
 *
 * Idempotent: it first claims the redemption by moving its local status from
 * unfulfilled to canceled, so a duplicated or retried job never sends a
 * second refund. A 429, a 5xx or a connection failure releases the claim and
 * throws, so the job retries. Any other failure releases the claim and is
 * logged, without the token, and is not retried:
 *
 * - 401: the token is invalid or lacks channel:manage:redemptions. The
 *   broadcaster must reconnect at /twitch/broadcaster/connect.
 * - 403 or 404: usually a reward created in the Twitch dashboard, which only
 *   its creator's client id may update. Recreate it with
 *   `php artisan music:create-song-reward`. A 403 can also mean the channel
 *   is no longer an affiliate or partner.
 *
 * Twitch's 422 means the redemption was already fulfilled or canceled, for
 * example by hand from the redemption queue. Its status is then recorded as
 * "unknown", Twitch's own value for a status we cannot tell.
 *
 * @see https://dev.twitch.tv/docs/api/reference/#update-redemption-status
 */
class RefundChannelPointRedemption implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const SCOPE = 'channel:manage:redemptions';

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public int $redemptionId,
        public string $reason,
    ) {}

    public function handle(): void
    {
        $redemption = ChannelPointRedemption::find($this->redemptionId);
        if ($redemption === null) {
            return;
        }

        $context = [
            'redemption_id' => $redemption->twitch_redemption_id,
            'broadcaster_id' => $redemption->broadcaster_id,
            'reward_id' => $redemption->reward_id,
            'reason' => $this->reason,
        ];

        $scopes = BroadcasterToken::where('broadcaster_id', $redemption->broadcaster_id)->first()?->scopes;
        if (is_array($scopes) && ! in_array(self::SCOPE, $scopes, true)) {
            Log::warning('Cannot refund a channel-point redemption: the broadcaster token lacks '.self::SCOPE.'. The broadcaster must reconnect at /twitch/broadcaster/connect.', $context);

            return;
        }

        if (! $this->claim()) {
            return; // already refunded, or no longer unfulfilled
        }

        try {
            $response = Twitch::cancelRedemption($redemption->broadcaster_id, $redemption->reward_id, $redemption->twitch_redemption_id);
        } catch (ConnectionException) {
            $this->retryLater($context, 'connection failed');
        } catch (RuntimeException $e) {
            // No token, or it could not be refreshed. Retrying will not help.
            $this->releaseClaim();
            Log::warning('Cannot refund a channel-point redemption: '.$e->getMessage(), $context);

            return;
        }

        if ($response->status() === 429 || $response->serverError()) {
            $this->retryLater($context, 'HTTP '.$response->status());
        }

        if ($response->successful()) {
            Log::info('Refunded a refused channel-point song request.', $context);

            return;
        }

        if ($response->status() === 422) {
            $this->setStatus('unknown');
            Log::info('Did not refund a channel-point redemption: Twitch says it was already fulfilled or canceled.', $context);

            return;
        }

        $this->releaseClaim();

        // Never log the request or response headers: the request carried the broadcaster's token.
        Log::warning('Twitch refused to refund a channel-point redemption. '.match ($response->status()) {
            401 => 'The broadcaster must reconnect at /twitch/broadcaster/connect to grant '.self::SCOPE.'.',
            403, 404 => 'Only the client id that created a reward can refund it: recreate the reward with `php artisan music:create-song-reward`.',
            default => '',
        }, $context + [
            'status' => $response->status(),
            'error' => (string) $response->json('message', ''),
        ]);
    }

    /**
     * @param  array<string, string>  $context
     */
    private function retryLater(array $context, string $reason): never
    {
        $this->releaseClaim();

        Log::warning('Refunding a channel-point redemption failed; it will be retried.', $context + ['failure' => $reason]);

        throw new RuntimeException('Refunding a channel-point redemption failed ('.$reason.'); retrying.');
    }

    /** Atomically move the redemption from unfulfilled to canceled. True only for the first claim. */
    private function claim(): bool
    {
        return ChannelPointRedemption::whereKey($this->redemptionId)
            ->where('status', 'unfulfilled')
            ->update(['status' => 'canceled']) === 1;
    }

    private function releaseClaim(): void
    {
        $this->setStatus('unfulfilled');
    }

    private function setStatus(string $status): void
    {
        ChannelPointRedemption::whereKey($this->redemptionId)->update(['status' => $status]);
    }
}
