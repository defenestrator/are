<?php

namespace App\Console\Commands;

use App\Jobs\PollYouTubeLiveChat;
use App\Models\YouTubeLiveChat;
use App\YouTube\YouTubeApi;
use Illuminate\Console\Command;

class YouTubeChat extends Command
{
    protected $signature = 'youtube:chat
        {videos?* : Live video ids or URLs, one per channel}
        {--stop : Stop reading these videos\' chat (every video, if none are given)}';

    protected $description = 'Start (or stop) reading YouTube live chat for chat commands, from the live video ids of the show.';

    public function handle(): int
    {
        $videoIds = array_values(array_unique(array_filter(array_map(self::videoId(...), (array) $this->argument('videos')))));

        if ($this->option('stop')) {
            return $this->stop($videoIds);
        }

        if ($videoIds === []) {
            $this->error('Give the live video id or URL of each channel, for example: php artisan youtube:chat dQw4w9WgXcQ');

            return self::FAILURE;
        }

        if (! config('services.youtube.api_key')) {
            $this->error('YOUTUBE_API_KEY is not set.');

            return self::FAILURE;
        }

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
