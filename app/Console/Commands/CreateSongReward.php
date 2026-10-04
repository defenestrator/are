<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Twitch;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Creates the channel-point song-request reward through Helix, under this
 * app's client id (#124). Twitch only lets the client id that created a
 * reward update its redemptions, so a reward made in the Twitch dashboard
 * cannot be refunded by ARE. Idempotent: if a reward with the same title
 * that ARE may manage already exists, its id is printed instead.
 *
 * @see https://dev.twitch.tv/docs/api/reference/#create-custom-rewards
 */
class CreateSongReward extends Command
{
    protected $signature = 'music:create-song-reward
        {--broadcaster= : Twitch user id of the channel; defaults to TWITCH_CHANNEL_ID}
        {--title=Request a song : Reward title, at most 45 characters}
        {--cost=500 : Cost in channel points}
        {--prompt=Type a song title or number from the stream-safe pack : Shown to the viewer, at most 200 characters}';

    protected $description = 'Create the channel-point reward for song requests, so ARE can refund refused requests.';

    public function handle(): int
    {
        $broadcasterId = (string) ($this->option('broadcaster') ?: User::getBroadcasterID());
        $title = trim((string) $this->option('title'));
        $prompt = trim((string) $this->option('prompt'));
        $cost = filter_var($this->option('cost'), FILTER_VALIDATE_INT);

        if (! in_array($broadcasterId, User::getBroadcasterIDs(), true)) {
            $this->error("{$broadcasterId} is not a broadcaster this app serves.");

            return self::FAILURE;
        }
        if ($title === '' || mb_strlen($title) > 45) {
            $this->error('The title must be between 1 and 45 characters.');

            return self::FAILURE;
        }
        if ($cost === false || $cost < 1) {
            $this->error('The cost must be a whole number of at least 1.');

            return self::FAILURE;
        }
        if (mb_strlen($prompt) > 200) {
            $this->error('The prompt must be at most 200 characters.');

            return self::FAILURE;
        }

        try {
            $existing = Twitch::manageableRewards($broadcasterId);
            if ($existing->failed()) {
                return $this->refused($existing, 'list the channel\'s rewards');
            }

            $match = collect($existing->json('data', []))
                ->first(fn (array $reward) => mb_strtolower((string) ($reward['title'] ?? '')) === mb_strtolower($title));

            if ($match !== null) {
                $this->info("ARE already manages a \"{$match['title']}\" reward on {$broadcasterId}.");

                return $this->printId((string) $match['id']);
            }

            $created = Twitch::createReward($broadcasterId, array_filter([
                'title' => $title,
                'cost' => $cost,
                'prompt' => $prompt,
                'is_user_input_required' => true,
                'is_enabled' => true,
            ], fn ($value) => $value !== ''));

            if ($created->failed()) {
                return $this->refused($created, 'create the reward');
            }
        } catch (ConnectionException $e) {
            $this->error('Could not reach Twitch: '.$e->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $e) {
            // No broadcaster token, or it could not be refreshed.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created the \"{$title}\" reward ({$cost} points) on {$broadcasterId}.");

        return $this->printId((string) $created->json('data.0.id'));
    }

    private function printId(string $id): int
    {
        $this->line("Reward id: {$id}");
        $this->line('Add it to MUSIC_SONG_REQUEST_REWARD_ID (comma-separate one id per channel), then run php artisan config:cache.');

        return self::SUCCESS;
    }

    private function refused(Response $response, string $action): int
    {
        // Twitch's message only; never the request, which carried the token.
        $this->error("Twitch refused to {$action} ({$response->status()}): ".(string) $response->json('message', ''));

        if ($response->status() === 401) {
            $this->line('The broadcaster must reconnect at /twitch/broadcaster/connect to grant channel:manage:redemptions.');
        } elseif ($response->status() === 403) {
            $this->line('Custom rewards need a Partner or Affiliate channel.');
        }

        return self::FAILURE;
    }
}
