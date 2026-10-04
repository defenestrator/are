<?php

namespace App\Console\Commands;

use App\Jobs\PollYouTubeLiveChat;
use App\Models\YouTubeLiveChat;
use App\YouTube\Quota;
use App\YouTube\YouTubeApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class YouTubeChat extends Command
{
    protected $signature = 'youtube:chat
        {videos?* : Live video ids or URLs, one per channel}
        {--stop : Stop reading these videos\' chat (every video, if none are given)}
        {--auto : Find each configured channel\'s live video with search.list and start reading it}';

    protected $description = 'Start (or stop) reading YouTube live chat for chat commands, from the live video ids of the show.';

    public function handle(): int
    {
        $videoIds = array_values(array_unique(array_filter(array_map(self::videoId(...), (array) $this->argument('videos')))));

        if ($this->option('stop')) {
            return $this->stop($videoIds);
        }

        if ($this->option('auto')) {
            return $this->auto();
        }

        if ($videoIds === []) {
            $this->error('Give the live video id or URL of each channel, for example: php artisan youtube:chat dQw4w9WgXcQ');

            return self::FAILURE;
        }

        if (! config('services.youtube.api_key')) {
            $this->error('YOUTUBE_API_KEY is not set.');

            return self::FAILURE;
        }

        return $this->start($videoIds);
    }

    /**
     * Find the live video of each configured channel whose chat ARE is not
     * already reading, and start reading it (#127).
     *
     * search.list costs 1 unit from the separate 100-calls/day search bucket,
     * so each channel is searched at most once per search_every_minutes
     * (15 by default, claimed atomically in the cache), and not at all once
     * the day's search bucket is spent. The scheduler runs this every minute
     * inside the show windows (App\YouTube\ShowWindows).
     */
    private function auto(): int
    {
        if (! config('services.youtube.api_key')) {
            $this->error('YOUTUBE_API_KEY is not set.');

            return self::FAILURE;
        }

        $channels = (array) config('services.youtube.channel_ids', []);
        if ($channels === []) {
            $this->error('YOUTUBE_CHANNEL_IDS is not set, so there is nothing to search.');

            return self::FAILURE;
        }

        $reading = YouTubeLiveChat::polling()->pluck('channel_id')->all();
        $every = max(1, (int) config('services.youtube.auto.search_every_minutes'));
        $found = [];

        foreach (array_values(array_diff($channels, $reading)) as $channelId) {
            if (Quota::used(Quota::SEARCH) >= Quota::limit(Quota::SEARCH)) {
                $this->warn('The day\'s search.list quota is spent; not searching until midnight PT.');

                break;
            }

            if (! Cache::add('youtube:auto-search:'.$channelId, true, now()->addMinutes($every))) {
                $this->line("{$channelId}: searched less than {$every} minutes ago.");

                continue;
            }

            $response = YouTubeApi::searchLive($channelId);
            if ($response->failed()) {
                $this->error("{$channelId}: search.list failed (".$response->status().'): '.(YouTubeApi::errorReason($response) ?? ''));

                continue;
            }

            $videoId = $response->json('items.0.id.videoId');
            if (is_string($videoId) && $videoId !== '') {
                $found[] = $videoId;
            } else {
                $this->line("{$channelId}: not live.");
            }
        }

        return $found === [] ? self::SUCCESS : $this->start(array_values(array_unique($found)));
    }

    /**
     * @param  list<string>  $videoIds
     */
    private function start(array $videoIds): int
    {
        // One videos.list call (1 unit) covers every channel's video.
        $response = YouTubeApi::videos($videoIds);
        if ($response->failed()) {
            $this->error('YouTube refused videos.list ('.$response->status().'): '.(YouTubeApi::errorReason($response) ?? $response->json('error.message', '')));

            return self::FAILURE;
        }

        $videos = collect((array) $response->json('items', []))->keyBy('id');
        $allowedChannels = (array) config('services.youtube.channel_ids', []);
        $failed = 0;

        foreach ($videoIds as $videoId) {
            $video = $videos->get($videoId);
            $channelId = (string) ($video['snippet']['channelId'] ?? '');
            $liveChatId = $video['liveStreamingDetails']['activeLiveChatId'] ?? null;

            $problem = match (true) {
                $video === null => 'no such video',
                $allowedChannels !== [] && ! in_array($channelId, $allowedChannels, true) => "it belongs to channel {$channelId}, which is not in YOUTUBE_CHANNEL_IDS",
                ! is_string($liveChatId) || $liveChatId === '' => 'it is not live, or its live chat is off',
                default => null,
            };

            if ($problem !== null) {
                $failed++;
                $this->error("{$videoId}: not started, because {$problem}.");

                continue;
            }

            $chat = YouTubeLiveChat::updateOrCreate(['video_id' => $videoId], [
                'channel_id' => $channelId,
                'live_chat_id' => $liveChatId,
                'title' => $video['snippet']['title'] ?? null,
                'status' => YouTubeLiveChat::POLLING,
                'next_page_token' => null,
                'poll_interval_ms' => (int) config('services.youtube.poll_floor_ms'),
                'next_poll_at' => null,
                'consecutive_errors' => 0,
                'started_at' => now(),
                'ended_at' => null,
                'end_reason' => null,
            ]);

            PollYouTubeLiveChat::dispatch($chat->id);
            $this->info("{$videoId}: reading chat for \"{$chat->title}\" on {$channelId}.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $videoIds
     */
    private function stop(array $videoIds): int
    {
        $chats = YouTubeLiveChat::polling()
            ->when($videoIds !== [], fn ($q) => $q->whereIn('video_id', $videoIds))
            ->get();

        foreach ($chats as $chat) {
            $chat->finish(YouTubeLiveChat::STOPPED, 'operator');
            $this->line("{$chat->video_id}: stopped.");
        }

        $this->info($chats->count().' chat(s) stopped.');

        return self::SUCCESS;
    }

    /**
     * Accepts a bare id or a watch, live, shorts or youtu.be URL.
     */
    public static function videoId(string $input): string
    {
        $input = trim($input);

        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $input)) {
            return $input;
        }

        if (preg_match('~(?:[?&]v=|youtu\.be/|/live/|/shorts/|/embed/)([A-Za-z0-9_-]{11})~', $input, $m)) {
            return $m[1];
        }

        return $input;
    }
}
