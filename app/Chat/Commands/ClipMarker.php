<?php

namespace App\Chat\Commands;

use App\Chat\ChatCommandInvocation;
use App\Chat\ChatCommandResult;
use App\Chat\ModeratorChatCommand;
use App\Clips\ClipHelix;
use App\Clips\StreamMarkerStatus;
use App\IdentityProvider;
use App\Jobs\Clips\CreateClipForMarker;
use App\Models\BroadcasterToken;
use App\Models\StreamMarker;
use App\Models\StreamSession;
use App\Models\User;
use App\QuestionQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * !clip [note]: mark this moment of the stream and clip the minute around it (#11).
 *
 * The broadcaster and moderators of the channel the message arrived on only:
 * as a ModeratorChatCommand, the registry refuses everyone else before
 * handle() runs, including a moderator of another served channel (#96).
 * Creates a Helix stream
 * marker with the broadcaster's stored token, because moderators are not
 * channel editors, then queues CreateClipForMarker to cut the clip from the
 * VOD while the stream is still live. Rate-limited per channel, on top of the
 * registry's per-person limit.
 */
class ClipMarker implements ModeratorChatCommand
{
    /** Helix rejects longer marker descriptions. */
    public const DESCRIPTION_MAX = 140;

    public function __construct(private ClipHelix $helix) {}

    public function names(): array
    {
        return ['clip'];
    }

    public function requiresUser(): bool
    {
        return true;
    }

    public function handle(ChatCommandInvocation $invocation): ChatCommandResult
    {
        /** @var User $user */
        $user = $invocation->user;

        // Markers live on Twitch streams. A channel this app does not serve,
        // or one whose broadcaster never connected, has no token to use.
        $channelId = $invocation->channelId;
        if ($invocation->provider !== IdentityProvider::Twitch || ! in_array($channelId, User::getBroadcasterIDs(), true)) {
            return ChatCommandResult::rejected('!clip only works in Twitch chat on a channel this app serves.');
        }

        if (! BroadcasterToken::where('broadcaster_id', $channelId)->exists()) {
            return ChatCommandResult::rejected('This channel is not connected yet, so !clip cannot mark it. The broadcaster needs to connect at /twitch/broadcaster/connect.');
        }

        // Counted only for this channel's mods (the registry refused everyone
        // else), so viewers cannot use up the channel's budget.
        $rateKey = 'clip-marker:'.$channelId;
        if (RateLimiter::tooManyAttempts($rateKey, (int) config('clips.per_channel_per_minute'))) {
            return ChatCommandResult::rejected('Too many clips on this channel this minute. Try again in '.RateLimiter::availableIn($rateKey).' s.');
        }
        RateLimiter::hit($rateKey, 60);

        $description = $this->description($invocation);
        $session = StreamSession::live()->where('broadcaster_id', $channelId)->latest('started_at')->first();

        $marker = null;
        $reason = 'Twitch did not return the marker.';
        $twitchSays = '';
        try {
            $response = $this->helix->createMarker($channelId, $description);
            if ($response->successful()) {
                $marker = $response->json('data.0');
                $reason = is_array($marker) && ! empty($marker['id']) ? null : $reason;
            } else {
                $reason = $this->markerFailure($response->status());
                $twitchSays = ClipHelix::message($response);
            }
        } catch (ConnectionException) {
            $reason = 'Could not reach Twitch to add a marker. Try !clip again.';
        } catch (RuntimeException $e) {
            // Twitch::broadcasterAccessToken() could not refresh the token.
            report($e);
            $reason = 'The broadcaster\'s Twitch token could not be refreshed. The broadcaster needs to reconnect at /twitch/broadcaster/connect.';
        }

        if ($reason !== null || ! is_array($marker)) {
            $reason ??= 'Twitch did not return the marker.';
            // Kept, so mods can see on /clips why nothing was clipped.
            StreamMarker::create([
                'stream_session_id' => $session?->id,
                'broadcaster_id' => $channelId,
                'description' => $description,
                'created_by_user_id' => $user->id,
                'status' => StreamMarkerStatus::MarkerFailed,
                'error' => $twitchSays !== '' ? $reason.' Twitch said: '.$twitchSays : $reason,
            ]);

            // A fixed template (#132): Twitch's own words go only to /clips.
            return ChatCommandResult::rejected($reason);
        }

        $streamMarker = StreamMarker::create([
            'stream_session_id' => $session?->id,
            'broadcaster_id' => $channelId,
            'twitch_marker_id' => (string) $marker['id'],
            'position_seconds' => (int) ($marker['position_seconds'] ?? 0),
            'description' => $description,
            'created_by_user_id' => $user->id,
            'status' => StreamMarkerStatus::ClipPending,
        ]);

        CreateClipForMarker::dispatch($streamMarker->id)
            ->delay(now()->addSeconds((int) config('clips.create_delay_seconds')));

        // A fixed template plus the position (#132). Kept short, because
        // PostChatReply refuses a reply containing the mod's note as a word.
        return ChatCommandResult::done('Marked at '.$streamMarker->position().'.');
    }

    /**
     * The mod's note, or who clipped it. Bidi controls are removed, as for
     * questions, and the text is cut to Helix's 140-character limit.
     */
    private function description(ChatCommandInvocation $invocation): string
    {
        $note = QuestionQueue::clean((string) preg_replace('/\p{Cc}+/u', ' ', $invocation->arguments));
        $text = $note !== '' ? $note : '!clip by '.$invocation->chatterName;

        return mb_substr($text, 0, self::DESCRIPTION_MAX);
    }

    /**
     * What to tell the mod when Twitch refuses the marker.
     */
    private function markerFailure(int $status): string
    {
        return match (true) {
            $status === 404 => 'Twitch would not add a marker: the channel must be live, not a rerun or premiere, with "Store past broadcasts" turned on.',
            $status === 429 => 'Twitch is rate-limiting this channel. Try !clip again in a minute.',
            $status === 401, $status === 403 => 'Twitch refused the broadcaster\'s token for markers. The broadcaster needs to reconnect at /twitch/broadcaster/connect.',
            $status === 400 => 'Twitch rejected the marker request.',
            default => 'Twitch could not add a marker right now (HTTP '.$status.').',
        };
    }
}
