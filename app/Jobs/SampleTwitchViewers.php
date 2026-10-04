<?php

namespace App\Jobs;

use App\Models\StreamSession;
use App\Models\StreamViewerSample;
use App\Twitch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Records concurrent viewers for every open stream session (#12), with one
 * Helix Get Streams call for all of them, using the app token.
 *
 * When it runs:
 * - stream.online queues one sample shortly after the stream starts;
 * - the scheduler queues one every few minutes while any session is open
 *   (routes/console.php). That tick is also the watchdog: nothing has to
 *   keep a job chain alive, so a lost job costs one sample, not the rest
 *   of the stream.
 *
 * Sampling stops by itself when stream.offline closes the session.
 *
 * A failed call is logged and skipped, not retried: the next tick takes a
 * fresh sample, and a late retry would record a stale count. Samples are
 * unique per session and minute, so a duplicated job records nothing twice.
 *
 * @see https://dev.twitch.tv/docs/api/reference/#get-streams
 */
class SampleTwitchViewers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Seconds a queued sample blocks a duplicate. */
    public int $uniqueFor = 60;

    public function handle(): void
    {
        /** @var Collection<int, StreamSession> $sessions */
        $sessions = StreamSession::live()->get();

        if ($sessions->isEmpty()) {
            return;
        }

        // Get Streams takes up to 100 user_id parameters, repeated, not as an array.
        $query = $sessions->pluck('broadcaster_id')->unique()->take(100)
            ->map(fn (string $id) => 'user_id='.rawurlencode($id))
            ->push('first=100')
            ->implode('&');

        try {
            $response = Twitch::asApp()->connectTimeout(3)->timeout(5)->get('/streams?'.$query);
        } catch (ConnectionException $e) {
            Log::warning('Could not sample Twitch viewers: connection failed.');

            return;
        } catch (RequestException $e) {
            // The app token could not be fetched. Log the status only: the
            // token request's body carried the client secret.
            Log::warning('Could not sample Twitch viewers: no app token.', ['status' => $e->response->status()]);

            return;
        }

        if ($response->failed()) {
            // Never log the response headers: the request carried the app token.
            Log::warning('Could not sample Twitch viewers.', ['status' => $response->status()]);

            return;
        }

        $sampledAt = now()->startOfMinute();

        foreach ((array) $response->json('data', []) as $stream) {
            if (! is_array($stream) || ($stream['type'] ?? '') !== 'live') {
                continue;
            }

            // Match on the stream id, so a stream that restarted under a new id
            // (whose stream.online we have not processed yet) is not credited
            // to the old session.
            $session = $sessions->first(fn (StreamSession $s) => $s->twitch_stream_id === (string) ($stream['id'] ?? '')
                && $s->broadcaster_id === (string) ($stream['user_id'] ?? ''));

            if ($session === null) {
                continue;
            }

            StreamViewerSample::query()->insertOrIgnore([
                'stream_session_id' => $session->id,
                'sampled_at' => $sampledAt,
                'viewer_count' => max(0, (int) ($stream['viewer_count'] ?? 0)),
            ]);
        }
    }
}
