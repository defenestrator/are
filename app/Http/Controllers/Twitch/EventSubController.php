<?php

namespace App\Http\Controllers\Twitch;

use App\Http\Controllers\Controller;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Receives Twitch EventSub webhooks.
 *
 * @see https://dev.twitch.tv/docs/eventsub/handling-webhook-events/
 */
class EventSubController extends Controller
{
    /** Twitch retries for a while; reject anything older than this as a possible replay. */
    private const MAX_AGE_MINUTES = 10;

    public function __invoke(Request $request): Response
    {
        $secret = config('services.twitch.eventsub_secret');
        $id = (string) $request->header('Twitch-Eventsub-Message-Id');
        $timestamp = (string) $request->header('Twitch-Eventsub-Message-Timestamp');
        $signature = (string) $request->header('Twitch-Eventsub-Message-Signature');

        if (! $secret || $id === '' || $timestamp === '' || $signature === '') {
            abort(403);
        }

        $expected = 'sha256='.hash_hmac('sha256', $id.$timestamp.$request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            abort(403);
        }

        try {
            $sentAt = Carbon::parse($timestamp);
        } catch (\Throwable) {
            abort(403);
        }
        if ($sentAt->lt(now()->subMinutes(self::MAX_AGE_MINUTES)) || $sentAt->gt(now()->addMinutes(self::MAX_AGE_MINUTES))) {
            abort(403);
        }

        // Twitch may deliver the same message more than once; handle each id once.
        // The id is recorded only after handling succeeds, so a failure is retried.
        $seenKey = 'twitch.eventsub.'.$id;
        if (Cache::has($seenKey)) {
            return response('', 204);
        }

        $payload = $request->json()->all();

        $response = match ($request->header('Twitch-Eventsub-Message-Type')) {
            'webhook_callback_verification' => response((string) ($payload['challenge'] ?? ''), 200, ['Content-Type' => 'text/plain']),
            'notification' => $this->notification($payload),
            'revocation' => $this->revocation($payload),
            default => response('', 204),
        };

        Cache::put($seenKey, true, now()->addMinutes(self::MAX_AGE_MINUTES * 2));

        return $response;
    }

    private function notification(array $payload): Response
    {
        $type = $payload['subscription']['type'] ?? null;
        $event = $payload['event'] ?? [];
        $broadcasterId = $event['broadcaster_user_id'] ?? null;
        $userId = $event['user_id'] ?? null;

        // Only act on channels this app is configured to serve.
        if (! $broadcasterId || ! $userId || ! in_array($broadcasterId, User::getBroadcasterIDs(), true)) {
            return response('', 204);
        }

        $key = ['broadcaster_id' => $broadcasterId, 'twitch_user_id' => $userId];

        match ($type) {
            'channel.ban' => TwitchBan::updateOrCreate($key, [
                'ends_at' => ($event['is_permanent'] ?? true) || empty($event['ends_at']) ? null : Carbon::parse($event['ends_at']),
            ]),
            'channel.unban' => TwitchBan::where($key)->delete(),
            'channel.moderator.add' => TwitchModerator::firstOrCreate($key),
            'channel.moderator.remove' => TwitchModerator::where($key)->delete(),
            default => null,
        };

        return response('', 204);
    }

    private function revocation(array $payload): Response
    {
        logger()->warning('Twitch revoked an EventSub subscription', [
            'type' => $payload['subscription']['type'] ?? null,
            'status' => $payload['subscription']['status'] ?? null,
            'condition' => $payload['subscription']['condition'] ?? null,
        ]);

        return response('', 204);
    }
}
